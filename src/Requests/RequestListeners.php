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
        $this->guard(function () {
            $this->monitor->increment('outgoing_requests');
        });
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
