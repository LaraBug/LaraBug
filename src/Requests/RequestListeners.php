<?php

namespace LaraBug\Requests;

use Throwable;
use LaraBug\Mail\MailEntry;
use LaraBug\Support\ClassName;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Events\NotificationFailed;

/**
 * The events that fill in a request record.
 *
 * Subscribed only when request tracking is on, and every handler is wrapped:
 * these fire inside the customer's request, and a listener that throws would
 * surface at whatever line happened to run a query.
 */
class RequestListeners
{
    public function __construct(
        protected readonly RequestMonitor $monitor,
        protected readonly Sampler $sampler,
        protected readonly ?QueryExplainer $explainer = null,
    ) {
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(RouteMatched::class, $this->onRouteMatched(...));
        $events->listen(QueryExecuted::class, $this->onQueryExecuted(...));
        $events->listen(CacheHit::class, $this->onCacheHit(...));
        $events->listen(CacheMissed::class, $this->onCacheMissed(...));
        $events->listen(KeyWritten::class, $this->onCacheWritten(...));
        $events->listen(KeyForgotten::class, $this->onCacheForgotten(...));
        $events->listen(JobQueued::class, $this->onJobQueued(...));

        // The mail itself is MailListeners', which records it against whichever
        // execution sent it: this request, or a job or command running in it.
        $events->listen(NotificationSent::class, $this->onNotificationSent(...));
        $events->listen(NotificationFailed::class, $this->onNotificationFailed(...));

        // Outgoing HTTP via the client's own events: no handler stack has to be
        // pushed onto a Guzzle client we do not own.
        $events->listen(ResponseReceived::class, $this->onOutgoingRequest(...));
        $events->listen(ConnectionFailed::class, $this->onOutgoingRequest(...));

        // The counter is what makes "this endpoint logs forty lines a request"
        // visible without storing forty lines against it.
        $events->listen(MessageLogged::class, $this->onMessageLogged(...));
    }

    public function onRouteMatched(object $event): void
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

            // The sampling decision predates routing, because a trace has to
            // start before there is a route to reason about. Now that there is
            // one, the ignore list gets its say.
            $this->sampler->reconsider($path);
        });
    }

    public function onQueryExecuted(object $event): void
    {
        $this->guard(function () use ($event) {
            if (! $this->sampler->decided()) {
                return;
            }

            $index = $this->monitor->recordQuery(
                (string) $event->sql,
                (string) $event->connectionName,
                (float) $event->time
            );

            if ($index === null) {
                return;
            }

            $this->explainer?->consider($index, $event);
        });
    }

    public function onCacheHit(object $event): void
    {
        $this->guard(function () use ($event) {
            $this->monitor->increment('cache_hits');
            $this->recordCacheEvent('hit', $event);
        });
    }

    public function onCacheMissed(object $event): void
    {
        $this->guard(function () use ($event) {
            $this->monitor->increment('cache_misses');
            $this->recordCacheEvent('miss', $event);
        });
    }

    public function onCacheWritten(object $event): void
    {
        $this->guard(function () use ($event) {
            $this->recordCacheEvent('write', $event);
        });
    }

    public function onCacheForgotten(object $event): void
    {
        $this->guard(function () use ($event) {
            $this->recordCacheEvent('forget', $event);
        });
    }

    /**
     * The key is templated to its shape; the ttl is only meaningful on a write.
     */
    private function recordCacheEvent(string $op, object $event): void
    {
        $this->monitor->recordCacheEvent([
            'op' => $op,
            'key_prefix' => $this->cacheKey((string) ($event->key ?? '')),
            'store' => (string) ($event->storeName ?? ''),
            'ttl' => $op === 'write' ? (int) ($event->seconds ?? 0) : 0,
        ]);
    }

    /**
     * The key as its shape rather than its instance.
     *
     * This used to keep the part up to the first colon, which lost the shape of
     * every key it did collapse (`user:8213:profile` became `user`) and
     * collapsed nothing at all in a key with no colon — which is exactly what a
     * session id, a rate limiter key and a scheduled task key are. Those went
     * to the rollup one row per value.
     *
     * @see CacheKeyTemplate for what collapses and why it holds no regex.
     */
    private function cacheKey(string $key): string
    {
        return CacheKeyTemplate::template($key);
    }

    public function onJobQueued(object $event): void
    {
        $this->guard(function () use ($event) {
            $this->monitor->increment('jobs_queued');

            // With job tracking on, the worker that delivers a queued mailable
            // records the send itself, with its real subject, recipients and
            // outcome; a marker here as well would count one message twice.
            // Without it, the dispatch is the only sight of the message anyone
            // gets, so it lands on the request that queued it.
            if (config('larabug.jobs.track_jobs', true)) {
                return;
            }

            $job = $event->job ?? null;

            if (! $job instanceof SendQueuedMailable) {
                return;
            }

            if (! is_object($job->mailable ?? null)) {
                return;
            }

            $this->monitor->recordMail(MailEntry::dispatched($job->mailable));
        });
    }

    public function onNotificationSent(object $event): void
    {
        $this->guard(function () use ($event) {
            $this->recordNotification($event, 1);
        });
    }

    public function onNotificationFailed(object $event): void
    {
        $this->guard(function () use ($event) {
            $this->recordNotification($event, 0);
        });
    }

    /**
     * Record one notification, one entry per channel.
     */
    private function recordNotification(object $event, int $success): void
    {
        $notification = $event->notification ?? null;
        $notifiable = $event->notifiable ?? null;

        $this->monitor->recordNotification([
            'notification' => is_object($notification) ? ClassName::normalised(get_class($notification)) : '',
            'channel' => (string) ($event->channel ?? ''),
            // The type, never the notifiable itself: the class is diagnostic and
            // the id is who got notified, which is not ours to keep.
            'notifiable_type' => is_object($notifiable) ? ClassName::normalised(get_class($notifiable)) : '',
            'success' => $success,
        ]);
    }

    public function onOutgoingRequest(object $event): void
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
     * the request path takes. Rebuilt rather than regexed so a value carrying
     * an & or = of its own cannot smuggle itself back in.
     *
     * The path is templated the way a cache key is: `/v1/customers/cus_4821`
     * and `/v1/customers/cus_93` are one call site, not two. The house rule is
     * already this everywhere else — routes become `/api/users/{id}`, queries
     * become fingerprints — and an id in a path is the same instance-shaped
     * value in a different position.
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
            .CacheKeyTemplate::template($parts['path'] ?? '');

        if (! isset($parts['query']) || $parts['query'] === '') {
            return $rebuilt;
        }

        parse_str($parts['query'], $params);

        $names = implode('&', array_map(fn ($key) => $key.'=', array_keys($params)));

        return $names === '' ? $rebuilt : $rebuilt.'?'.$names;
    }

    /**
     * The round trip in milliseconds, off Guzzle's transfer stats which the
     * Http client hangs on the response. Zero when the call never got one.
     */
    private function outgoingDuration(?object $response): float
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
     */
    private function outgoingError(object $event, bool $failed): string
    {
        if (! $failed) {
            return '';
        }

        if (isset($event->exception) && $event->exception instanceof Throwable) {
            return mb_substr($event->exception->getMessage(), 0, 255);
        }

        return 'Connection failed';
    }

    public function onMessageLogged(object $event): void
    {
        $this->guard(function () {
            $this->monitor->recordLog();
        });
    }

    protected function guard(callable $callback): void
    {
        // The SDK's own EXPLAIN, and the cache write claiming its interval,
        // are not the application's work and never land on its record.
        if ($this->explainer?->explaining()) {
            return;
        }

        try {
            $callback();
        } catch (Throwable) {
            // Never let instrumentation surface in the application's own stack.
        }
    }
}
