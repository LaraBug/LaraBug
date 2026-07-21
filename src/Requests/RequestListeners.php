<?php

namespace LaraBug\Requests;

use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * The events that fill in a request record.
 *
 * Subscribed only when request tracking is on, and every handler is wrapped:
 * these fire inside the customer's request, and a listener that throws would
 * surface at whatever line happened to run a query.
 */
class RequestListeners
{
    /** @var RequestMonitor */
    protected $monitor;

    /** @var Sampler */
    protected $sampler;

    public function __construct(RequestMonitor $monitor, Sampler $sampler)
    {
        $this->monitor = $monitor;
        $this->sampler = $sampler;
    }

    /**
     * Registered one at a time rather than by returning a map, which the
     * dispatcher only understands from Laravel 8. This package supports 6, and
     * a subscriber that quietly listens to nothing is worse than one that fails
     * loudly.
     *
     * Events that do not exist on older versions, such as JobQueued, are named
     * as strings and simply never fire.
     */
    public function subscribe(Dispatcher $events): void
    {
        $events->listen('Illuminate\Routing\Events\RouteMatched', [$this, 'onRouteMatched']);
        $events->listen('Illuminate\Database\Events\QueryExecuted', [$this, 'onQueryExecuted']);
        $events->listen('Illuminate\Cache\Events\CacheHit', [$this, 'onCacheHit']);
        $events->listen('Illuminate\Cache\Events\CacheMissed', [$this, 'onCacheMissed']);
        $events->listen('Illuminate\Queue\Events\JobQueued', [$this, 'onJobQueued']);
        $events->listen('Illuminate\Mail\Events\MessageSent', [$this, 'onMailSent']);
        $events->listen('Illuminate\Notifications\Events\NotificationSent', [$this, 'onNotificationSent']);

        // Outgoing HTTP, via the client's own event rather than Guzzle
        // middleware: the event exists from Laravel 8 and needs no handler
        // stack to be pushed onto a client we do not own.
        $events->listen('Illuminate\Http\Client\Events\ResponseReceived', [$this, 'onOutgoingRequest']);
        $events->listen('Illuminate\Http\Client\Events\ConnectionFailed', [$this, 'onOutgoingRequest']);

        // Every log line written while this request was being served. The
        // counter is what makes "this endpoint logs forty lines a request"
        // visible without storing forty lines against it.
        $events->listen('Illuminate\Log\Events\MessageLogged', [$this, 'onMessageLogged']);
    }

    public function onRouteMatched($event): void
    {
        $this->guard(function () use ($event) {
            $route = $event->route;

            $path = '/'.ltrim($route->uri(), '/');

            $this->monitor->setRoute([
                'path' => $path,
                'name' => (string) $route->getName(),
                'domain' => (string) $route->getDomain(),
                'action' => (string) $route->getActionName(),
                'methods' => $route->methods(),
            ]);

            // The decision was made before routing, because a trace has to
            // start before there is a route to reason about. Now that there is
            // one, the ignore list gets its say.
            $this->sampler->reconsider($path);
        });
    }

    public function onQueryExecuted($event): void
    {
        $this->guard(function () use ($event) {
            if (! $this->sampler->decided()) {
                return;
            }

            $this->monitor->recordQuery(
                (string) $event->sql,
                (string) $event->connectionName,
                (float) $event->time
            );
        });
    }

    public function onCacheHit($event): void
    {
        $this->guard(function () {
            $this->monitor->increment('cache_hits');
        });
    }

    public function onCacheMissed($event): void
    {
        $this->guard(function () {
            $this->monitor->increment('cache_misses');
        });
    }

    public function onJobQueued($event): void
    {
        $this->guard(function () {
            $this->monitor->increment('jobs_queued');
        });
    }

    public function onMailSent($event): void
    {
        $this->guard(function () {
            $this->monitor->increment('mail_sent');
        });
    }

    public function onNotificationSent($event): void
    {
        $this->guard(function () {
            $this->monitor->increment('notifications_sent');
        });
    }

    public function onOutgoingRequest($event): void
    {
        $this->guard(function () use ($event) {
            $request = $event->request ?? null;

            if ($request === null) {
                return;
            }

            // ConnectionFailed carries no response: the call never completed.
            $response = $event->response ?? null;
            $failed = $response === null;

            $url = (string) $request->url();

            // recordOutgoing carries the counter, so a request that fans out
            // past the cap is still counted while only the first calls are kept.
            $this->monitor->recordOutgoing([
                'method' => (string) $request->method(),
                'host' => (string) parse_url($url, PHP_URL_HOST),
                'url' => $this->strippedUrl($url),
                'status_code' => $response ? (int) $response->status() : 0,
                'duration_ms' => $this->outgoingDuration($response),
                'failed' => $failed ? 1 : 0,
                'error' => $this->outgoingError($event, $failed),
            ]);
        });
    }

    /**
     * The url with its query values stripped, the names kept, the same stance
     * the request path takes. Rebuilt rather than regexed so a value carrying an
     * & or = of its own cannot smuggle itself back in.
     */
    private function strippedUrl(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return '';
        }

        $rebuilt = (isset($parts['scheme']) ? $parts['scheme'].'://' : '')
            .($parts['host'] ?? '')
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '');

        if (! isset($parts['query']) || $parts['query'] === '') {
            return $rebuilt;
        }

        parse_str($parts['query'], $params);

        $names = implode('&', array_map(function ($key) {
            return $key.'=';
        }, array_keys($params)));

        return $names === '' ? $rebuilt : $rebuilt.'?'.$names;
    }

    /**
     * The round trip in milliseconds, off Guzzle's transfer stats which the Http
     * client hangs on the response. Zero when the call never got one.
     *
     * @param  mixed  $response
     */
    private function outgoingDuration($response): float
    {
        if ($response === null) {
            return 0.0;
        }

        $stats = $response->transferStats ?? null;

        if ($stats === null || ! method_exists($stats, 'getTransferTime') || $stats->getTransferTime() === null) {
            return 0.0;
        }

        return round($stats->getTransferTime() * 1000, 3);
    }

    /**
     * A short reason a call failed, for the ones that never got a response.
     * ConnectionFailed grew an exception in later Laravel; older versions carry
     * only the request, so a generic marker is the most that can be said.
     *
     * @param  mixed  $event
     */
    private function outgoingError($event, bool $failed): string
    {
        if (! $failed) {
            return '';
        }

        if (isset($event->exception) && $event->exception instanceof \Throwable) {
            return mb_substr($event->exception->getMessage(), 0, 255);
        }

        return 'Connection failed';
    }

    public function onMessageLogged($event): void
    {
        $this->guard(function () {
            $this->monitor->recordLog();
        });
    }

    protected function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            // Never let instrumentation surface in the application's own stack.
        }
    }
}
