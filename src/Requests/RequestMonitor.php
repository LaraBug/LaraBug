<?php

namespace LaraBug\Requests;

use Throwable;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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
    protected array $marks = [];

    /** @var array<string, int> */
    protected array $counters = [
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
    protected array $queries = [];

    /** @var array<int, array<string, mixed>> */
    protected array $outgoing = [];

    /** @var array<int, array<string, mixed>> */
    protected array $mail = [];

    /** @var array<int, array<string, mixed>> */
    protected array $notifications = [];

    /** @var array<int, array<string, mixed>> */
    protected array $cache = [];

    /** @var array<string, mixed>|null */
    protected ?array $route = null;

    protected string $exceptionId = '';

    protected readonly int $maxQueries;

    protected readonly int $maxOutgoing;

    protected readonly int $maxMail;

    protected readonly int $maxNotifications;

    protected readonly int $maxCacheEvents;

    public function __construct()
    {
        $this->maxQueries = (int) config('larabug.requests.max_queries', 100);
        $this->maxOutgoing = (int) config('larabug.requests.max_outgoing', 50);
        $this->maxMail = (int) config('larabug.requests.max_mail', 50);
        $this->maxNotifications = (int) config('larabug.requests.max_notifications', 50);
        $this->maxCacheEvents = (int) config('larabug.requests.max_cache_events', 100);

        // LARAVEL_START is set in public/index.php before the framework boots,
        // so it is the only honest answer to "when did this request begin".
        // Without it bootstrap would report as nothing.
        $this->marks['start'] = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
    }

    public function mark(string $name): void
    {
        $this->marks[$name] = microtime(true);
    }

    /**
     * Whether the middleware got as far as marking where the action began. A
     * terminate that runs after a handle that could not started nothing worth
     * recording, and has no failure to promote.
     */
    public function started(): bool
    {
        return isset($this->marks['action_start']);
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

    /**
     * The exception this request threw, set from the report path rather than
     * guessed from the status code: a 500 can be returned deliberately, and an
     * exception can be reported on a request that still answers 200.
     */
    public function recordException(string $exceptionId = ''): void
    {
        $this->counters['exceptions']++;

        if ($exceptionId !== '' && $this->exceptionId === '') {
            $this->exceptionId = $exceptionId;
        }
    }

    public function recordLog(): void
    {
        $this->counters['logs']++;
    }

    /**
     * The statement is kept with its placeholders and the bindings are dropped
     * here rather than filtered later: a value in a WHERE clause is customer
     * data, and the safest place to not send it is the place it would
     * otherwise be collected.
     */
    public function recordQuery(string $sql, string $connection, float $durationMs): void
    {
        $this->counters['queries']++;

        // The counter keeps counting past the cap: a request running ten
        // thousand queries is worth knowing about, and the reason to look at
        // it is the number rather than the ten thousandth statement.
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
     * Record one outbound HTTP call. The counter keeps counting past the cap,
     * the same as queries. The url arrives with its query values already
     * stripped by the listener: a token in a callback url is customer data we
     * have no business storing.
     *
     * @param  array<string, mixed>  $call
     */
    public function recordOutgoing(array $call): void
    {
        $this->counters['outgoing_requests']++;

        if (count($this->outgoing) >= $this->maxOutgoing) {
            return;
        }

        $this->outgoing[] = $call;
    }

    /**
     * Record one message sent. The counter keeps counting past the cap. The
     * recipients arrive as domains rather than addresses unless the
     * application opted the full ones in: who a request emailed is personal
     * data, which service it emailed is the diagnostic part.
     *
     * @param  array<string, mixed>  $message
     */
    public function recordMail(array $message): void
    {
        $this->counters['mail_sent']++;

        if (count($this->mail) >= $this->maxMail) {
            return;
        }

        $this->mail[] = $message;
    }

    /**
     * Record one notification sent, one entry per channel: a notification
     * going out over mail and database is two sends, which is what the
     * notifiable actually receives. The notifiable is kept as its class,
     * never its id: which model type gets notified is diagnostic, which row
     * is personal data.
     *
     * @param  array<string, mixed>  $notification
     */
    public function recordNotification(array $notification): void
    {
        $this->counters['notifications_sent']++;

        if (count($this->notifications) >= $this->maxNotifications) {
            return;
        }

        $this->notifications[] = $notification;
    }

    /**
     * Record one cache operation. The hit and miss counters are kept by their
     * own listeners, so this only buffers the detail and is capped on its own:
     * a request that reads the cache a thousand times is common, and the first
     * hundred operations are enough to see its shape.
     *
     * @param  array<string, mixed>  $event
     */
    public function recordCacheEvent(array $event): void
    {
        if (count($this->cache) >= $this->maxCacheEvents) {
            return;
        }

        $this->cache[] = $event;
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
            // tokens all live in a query string.
            'path' => '/'.ltrim($request->path(), '/'),
            'query_keys' => implode(',', array_keys($request->query())),

            'status_code' => $response->getStatusCode(),

            'duration_ms' => round(array_sum($stages), 3),

            'sample_rate' => $sampleRate,

            // The same id the log lines and the exception from this execution
            // carry, which is the whole reason any of them is worth joining.
            'trace_id' => TraceContext::id(),
            'exception_id' => $this->exceptionId,

            'user_agent' => (string) $request->userAgent(),
            'host' => (string) gethostname(),

            // Redacted here, not on the way out: the headers most worth losing
            // are the ones an exception in between would otherwise carry.
            'headers' => $this->headers($request),

            // Only when the request failed, and only when asked for. A request
            // body is the highest-value thing in this payload and the one with
            // no business being stored for the requests that worked.
            'payload' => $this->payload($request, $response),

            'memory_peak_kb' => (int) round(memory_get_peak_usage(true) / 1024),
            'request_size' => strlen($request->getContent()),
            'response_size' => strlen((string) $response->getContent()),

            'environment' => (string) config('app.env'),
            'release' => (string) config('larabug.project_version', ''),

            'user_identifier' => $this->userIdentifier(),
            'ip' => (string) config('larabug.requests.capture_ip', true) ? (string) $request->ip() : '',

            'sql' => $this->queries,
            'outgoing' => $this->outgoing,
            'mail' => $this->mail,
            'notifications' => $this->notifications,
            'cache' => $this->cache,
        ], $stages, $this->counters);
    }

    /**
     * Request headers, minus the ones that are credentials. An
     * allow-by-default list with an explicit deny, rather than the reverse: a
     * header nobody thought about is usually diagnostic, and the handful that
     * are not are well known.
     *
     * @return string JSON
     */
    protected function headers(Request $request): string
    {
        if (! config('larabug.requests.capture_headers', true)) {
            return '';
        }

        // The fallback matters: an application that published its config
        // before these keys existed reads nothing from the file, and an empty
        // list here would store its Authorization header in full. The default
        // is the list, not the absence of one.
        $redact = array_map('strtolower', (array) config('larabug.requests.redact_headers', [
            'authorization',
            'cookie',
            'set-cookie',
            'x-api-key',
            'x-csrf-token',
            'x-xsrf-token',
            'proxy-authorization',
        ]));

        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $value = is_array($values) ? implode(', ', $values) : (string) $values;

            // A constant marker, not one that preserves the length: the length
            // of a secret is itself something worth not publishing.
            $headers[$name] = in_array(strtolower($name), $redact, true) ? '[redacted]' : $value;
        }

        return (string) json_encode($headers);
    }

    /**
     * The request body, on failure only: the body is what you need to
     * reproduce the request that broke, and what you have no reason to hold
     * for the thousands that did not.
     */
    protected function payload(Request $request, Response $response): string
    {
        if (! config('larabug.requests.capture_payload_on_error', false)) {
            return '';
        }

        if ($response->getStatusCode() < 500) {
            return '';
        }

        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS', 'TRACE'], true)) {
            return '';
        }

        $redact = array_map('strtolower', (array) config('larabug.requests.redact_fields', [
            'password',
            'password_confirmation',
            'token',
            'secret',
            'card',
            'cvv',
            'iban',
        ]));

        return (string) json_encode($this->redact($request->all(), $redact));
    }

    /**
     * Replace sensitive values anywhere in a body. Depth-first and by
     * substring, because the field that matters is rarely at the top level
     * under exactly the name the config lists: a checkout posts card[cvv], a
     * registration posts user.password_confirmation.
     *
     * @param  array<int|string, mixed>  $input
     * @param  array<int, string>  $redact  lowercased needles
     * @return array<int|string, mixed>
     */
    protected function redact(array $input, array $redact): array
    {
        foreach ($input as $key => $value) {
            // The key is judged before the value is walked into: a matching
            // key takes its whole branch with it, so 'card' removes
            // card[number] as well as card[cvv].
            if ($this->isSensitive($key, $redact)) {
                $input[$key] = '[redacted]';

                continue;
            }

            // An upload is a file handle, not data: no JSON form worth sending
            // and contents that are not ours to hold, so it is described
            // rather than serialised.
            if ($value instanceof UploadedFile) {
                $input[$key] = '[file: '.$value->getClientOriginalName().']';

                continue;
            }

            if (is_array($value)) {
                $input[$key] = $this->redact($value, $redact);
            }
        }

        return $input;
    }

    /**
     * @param  int|string  $key
     * @param  array<int, string>  $redact  lowercased needles
     */
    protected function isSensitive(int|string $key, array $redact): bool
    {
        $name = strtolower((string) $key);

        foreach ($redact as $needle) {
            if ($needle !== '' && str_contains($name, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The seven stages, in the order a request passes through them. Each is
     * the gap between two marks, and a mark that was never set closes its
     * stage at zero rather than borrowing from its neighbour: a request that
     * threw in middleware never reached render, and reporting render time for
     * it would be inventing a number.
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
            [$from, $to] = $pair;

            $stages[$stage] = isset($this->marks[$from], $this->marks[$to])
                ? round(max(0, ($this->marks[$to] - $this->marks[$from]) * 1000), 3)
                : 0.0;
        }

        return $stages;
    }

    /**
     * The rollup key, hashed here rather than on ingest. Sorted methods,
     * domain and route path, which is Nightwatch's key and the right one: it
     * groups by what the application defines rather than by what the client
     * typed, so /users/1 and /users/2 are one row.
     */
    protected function group(Request $request): string
    {
        $methods = $this->routeValue('methods', [$request->getMethod()]);

        if (! is_array($methods)) {
            $methods = [(string) $methods];
        }

        sort($methods);

        // md5 for the same reason QueryNormaliser keeps it: a stable grouping
        // key, not a signature.
        return md5(implode('|', $methods).','.$this->routeValue('domain', '').','.$this->routeValue('path', ''));
    }

    protected function routeValue(string $key, mixed $default): mixed
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
        } catch (Throwable) {
            return '';
        }

        if (! $user) {
            return '';
        }

        return (string) $user->getAuthIdentifier();
    }
}
