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

    public function subscribe(Dispatcher $events): array
    {
        return [
            'Illuminate\Routing\Events\RouteMatched' => 'onRouteMatched',
            'Illuminate\Database\Events\QueryExecuted' => 'onQueryExecuted',
            'Illuminate\Cache\Events\CacheHit' => 'onCacheHit',
            'Illuminate\Cache\Events\CacheMissed' => 'onCacheMissed',
            'Illuminate\Queue\Events\JobQueued' => 'onJobQueued',
            'Illuminate\Mail\Events\MessageSent' => 'onMailSent',
            'Illuminate\Notifications\Events\NotificationSent' => 'onNotificationSent',
        ];
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

    protected function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            // Never let instrumentation surface in the application's own stack.
        }
    }
}
