<?php

namespace LaraBug\Requests;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The state of the request being served, and the record it becomes.
 *
 * One instance per execution, held as a singleton. Everything that wants to
 * contribute — the middleware marking stage boundaries, the query listener, the
 * cache listeners — writes here, and the middleware's terminate hands the
 * finished record to the buffer.
 *
 * A flat record with fixed duration columns, not a span tree. Seven numbers
 * that sum to the total answer where the time went for every request at fixed
 * cost; a tree costs parent ids, ordering and unbounded cardinality to answer
 * the same question for the few requests anyone opens.
 */
class RequestMonitor
{
    /** @var array<string, float> Wall-clock marks, in seconds. */
    protected $marks = [];

    /** @var array<string, int> */
    protected $counters = [
        'queries' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'outgoing_requests' => 0,
        'jobs_queued' => 0,
        'mail_sent' => 0,
        'notifications_sent' => 0,
        'exceptions' => 0,
        'logs' => 0,
    ];

    /** @var array<int, array<string, mixed>> */
    protected $queries = [];

    /** @var array<string, mixed>|null */
    protected $route = null;

    /** @var string */
    protected $traceId = '';

    /** @var int */
    protected $maxQueries;

    public function __construct()
    {
        $this->maxQueries = (int) config('larabug.requests.max_queries', 100);

        // LARAVEL_START is set in public/index.php before the framework boots,
        // so it is the only honest answer to "when did this request begin".
        // Without it the earliest we can see is our own service provider, and
        // bootstrap would report as nothing.
        $this->marks['start'] = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
    }

    public function mark(string $name): void
    {
        $this->marks[$name] = microtime(true);
    }

    public function increment(string $counter, int $by = 1): void
    {
        if (isset($this->counters[$counter])) {
            $this->counters[$counter] += $by;
        }
    }

    /**
     * @param  array<string, mixed>  $route
     */
    public function setRoute(array $route): void
    {
        $this->route = $route;
    }

    public function setTraceId(string $traceId): void
    {
        $this->traceId = $traceId;
    }

    /**
     * Record one query.
     *
     * The statement is kept with its placeholders and the bindings are dropped
     * on the floor here rather than filtered later: a value in a WHERE clause is
     * customer data, and the safest place to not send it is the place it would
     * otherwise be collected.
     */
    public function recordQuery(string $sql, string $connection, float $durationMs): void
    {
        $this->counters['queries']++;

        // The counter keeps counting past the cap. A request running ten
        // thousand queries is worth knowing about, and the reason to look at it
        // is the number rather than the ten thousandth statement.
        if (count($this->queries) >= $this->maxQueries) {
            return;
        }

        $normalised = QueryNormaliser::normalise($sql);

        $this->queries[] = [
            'sql' => $normalised,
            'hash' => QueryNormaliser::hash($connection, $normalised),
            'connection' => $connection,
            'duration_ms' => round($durationMs, 3),
        ];
    }

    /**
     * The finished record.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request, Response $response, float $sampleRate): array
    {
        $stages = $this->stages();

        return array_merge([
            'timestamp' => gmdate('Y-m-d H:i:s', (int) $this->marks['start']).'.'.
                str_pad((string) (int) (($this->marks['start'] - (int) $this->marks['start']) * 1000), 3, '0', STR_PAD_LEFT),

            'group' => $this->group($request),

            'method' => $request->getMethod(),
            'route_path' => $this->routeValue('path', ''),
            'route_name' => $this->routeValue('name', ''),
            'route_domain' => $this->routeValue('domain', ''),
            'route_action' => $this->routeValue('action', ''),

            // The path, and the names of the query parameters. Never their
            // values: password reset tokens, signed url signatures and invite
            // tokens all live in a query string, and a customer who learns we
            // stored one learns it too late.
            'path' => '/'.ltrim($request->path(), '/'),
            'query_keys' => implode(',', array_keys($request->query())),

            'status_code' => $response->getStatusCode(),

            'duration_ms' => round(array_sum($stages), 3),

            'sample_rate' => $sampleRate,
            'trace_id' => $this->traceId,

            'memory_peak_kb' => (int) round(memory_get_peak_usage(true) / 1024),
            'request_size' => strlen($request->getContent()),
            'response_size' => strlen((string) $response->getContent()),

            'environment' => (string) config('app.env'),
            'release' => (string) config('larabug.project_version', ''),

            'user_identifier' => $this->userIdentifier(),
            'ip' => (string) config('larabug.requests.capture_ip', true) ? (string) $request->ip() : '',

            'sql' => $this->queries,
        ], $stages, $this->counters);
    }

    /**
     * The seven stages, in the order a request passes through them.
     *
     * Each is the gap between two marks, and a mark that was never set closes
     * its stage at zero rather than borrowing from its neighbour: a request
     * that threw in middleware never reached render, and reporting render time
     * for it would be inventing a number.
     *
     * @return array<string, float>
     */
    protected function stages(): array
    {
        $order = [
            'bootstrap_ms' => ['start', 'booted'],
            'before_middleware_ms' => ['booted', 'action_start'],
            'action_ms' => ['action_start', 'action_end'],
            'render_ms' => ['action_end', 'response_prepared'],
            'after_middleware_ms' => ['response_prepared', 'response_sent'],
            'sending_ms' => ['response_sent', 'terminating'],
            'terminating_ms' => ['terminating', 'terminated'],
        ];

        $stages = [];

        foreach ($order as $stage => $pair) {
            list($from, $to) = $pair;

            $stages[$stage] = isset($this->marks[$from], $this->marks[$to])
                ? round(max(0, ($this->marks[$to] - $this->marks[$from]) * 1000), 3)
                : 0.0;
        }

        return $stages;
    }

    /**
     * The rollup key, hashed here rather than on ingest.
     *
     * Sorted methods, domain and route path, which is Nightwatch's key and the
     * right one: it groups by what the application defines rather than by what
     * the client typed, so /users/1 and /users/2 are one row.
     */
    protected function group(Request $request): string
    {
        $methods = $this->routeValue('methods', [$request->getMethod()]);

        if (! is_array($methods)) {
            $methods = [(string) $methods];
        }

        sort($methods);

        return hash('xxh128', implode('|', $methods).','.$this->routeValue('domain', '').','.$this->routeValue('path', ''));
    }

    /**
     * @return mixed
     */
    protected function routeValue(string $key, $default)
    {
        if ($this->route === null || ! array_key_exists($key, $this->route)) {
            return $default;
        }

        return $this->route[$key];
    }

    protected function userIdentifier(): string
    {
        if (! config('larabug.requests.capture_user', true)) {
            return '';
        }

        try {
            $user = auth()->user();
        } catch (\Throwable $e) {
            return '';
        }

        if (! $user) {
            return '';
        }

        return (string) $user->getAuthIdentifier();
    }
}
