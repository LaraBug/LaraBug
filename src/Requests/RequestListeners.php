<?php

namespace LaraBug\Requests;

use Throwable;
use Illuminate\Mail\Mailable;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Mail\Events\MessageSending;
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
    /** @var array<int, float> Send-start marks, in seconds, keyed by message. */
    protected array $mailStartedAt = [];

    public function __construct(
        protected readonly RequestMonitor $monitor,
        protected readonly Sampler $sampler,
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

        // Paired: the sending event only starts a timer, the sent event is what
        // records the message. A send that throws never reaches sent and leaves
        // only a mark that the next send overwrites.
        $events->listen(MessageSending::class, $this->onMailSending(...));
        $events->listen(MessageSent::class, $this->onMailSent(...));
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

            $this->monitor->recordQuery(
                (string) $event->sql,
                (string) $event->connectionName,
                (float) $event->time
            );
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
     * The key is narrowed to a prefix unless the application opted the full
     * keys in; the ttl is only meaningful on a write.
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
     * Laravel's keys are routinely "prefix:id", and the prefix is the
     * diagnostic part while the id is customer data: the part up to the first
     * colon keeps the former and drops the latter. A key with no colon is
     * bounded to a fixed length; an application that opted in keeps its keys
     * whole.
     */
    private function cacheKey(string $key): string
    {
        if (config('larabug.requests.capture_cache_keys', false)) {
            return mb_substr($key, 0, 255);
        }

        $colon = strpos($key, ':');

        if ($colon > 0) {
            return mb_substr($key, 0, $colon);
        }

        return mb_substr($key, 0, 64);
    }

    public function onJobQueued(object $event): void
    {
        $this->guard(function () use ($event) {
            $this->monitor->increment('jobs_queued');

            // A queued mailable's send happens a worker away, where no request
            // is being recorded. Counted at dispatch, it lands on the request
            // that queued it — or is never seen inside one at all.
            $job = $event->job ?? null;

            if ($job instanceof SendQueuedMailable) {
                $this->recordQueuedMail($job->mailable ?? null);
            }
        });
    }

    public function onMailSending(object $event): void
    {
        $this->guard(function () use ($event) {
            if ($this->isNotificationMail($event)) {
                return;
            }

            $message = $event->message ?? null;

            if ($message === null) {
                return;
            }

            $this->mailStartedAt[spl_object_id($message)] = microtime(true);
        });
    }

    public function onMailSent(object $event): void
    {
        $this->guard(function () use ($event) {
            // A notification sent over mail fires this too, and the
            // notification path already records it; bowing out keeps it from
            // counting as both, the same split Nightwatch draws.
            if ($this->isNotificationMail($event)) {
                return;
            }

            $message = $event->message ?? null;

            if ($message === null) {
                // A mailer that fired the event without a message still sent
                // one; keep the tile honest even when there is nothing to detail.
                $this->monitor->recordMail([]);

                return;
            }

            $to = $this->mailAddresses($this->recipients($message, 'getTo'));
            $cc = $this->mailAddresses($this->recipients($message, 'getCc'));
            $bcc = $this->mailAddresses($this->recipients($message, 'getBcc'));

            $this->monitor->recordMail([
                'mailable' => $this->mailableClass(),
                'subject' => $this->mailSubject($message),
                'to_count' => count($to),
                'cc_count' => count($cc),
                'bcc_count' => count($bcc),
                'recipient_domains' => $this->mailRecipients(array_merge($to, $cc, $bcc)),
                'queued' => 0,
                'duration_ms' => $this->mailDuration($message),
            ]);
        });
    }

    /**
     * Neither mail event carries the mailable, so it is read off the call
     * stack instead: a Mailable's send() is always a frame below the event
     * that fires inside it. Empty for mail sent without a mailable —
     * Mail::raw() and the like — where the subject is the only name a
     * message has.
     */
    private function mailableClass(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 50) as $frame) {
            $object = $frame['object'] ?? null;

            if ($object instanceof Mailable) {
                return get_class($object);
            }
        }

        return '';
    }

    private function mailSubject(object $message): string
    {
        if (! method_exists($message, 'getSubject')) {
            return '';
        }

        return (string) $message->getSubject();
    }

    /**
     * One address list off a message, across mail engines: Symfony's Email has
     * the getters and returns Address objects, the older Swift message had the
     * same getter names and returned an [address => name] map, and a message
     * without the getter contributes nobody.
     *
     * @return array<int|string, mixed>
     */
    private function recipients(object $message, string $getter): array
    {
        if (! method_exists($message, $getter)) {
            return [];
        }

        return (array) $message->{$getter}();
    }

    /**
     * The address strings in a recipient list, whichever engine produced it:
     * Symfony hands over Address objects, Swift keyed the address and put the
     * name in the value.
     *
     * @param  array<int|string, mixed>  $recipients
     * @return array<int, string>
     */
    private function mailAddresses(array $recipients): array
    {
        $addresses = [];

        foreach ($recipients as $key => $value) {
            if (is_object($value) && method_exists($value, 'getAddress')) {
                $addresses[] = (string) $value->getAddress();

                continue;
            }

            if (is_string($key) && $key !== '') {
                $addresses[] = $key;

                continue;
            }

            if (is_string($value) && $value !== '') {
                $addresses[] = $value;
            }
        }

        return $addresses;
    }

    /**
     * Stored as the domains a message reached, deduped, never the addresses:
     * a bounce is a bounce to gmail.com, not to a person, and the local part
     * is the customer data the whole request position rests on not keeping.
     * Opting in capture_mail_recipients keeps the full addresses, the same
     * shape the payload capture takes.
     *
     * @param  array<int, string>  $addresses
     */
    private function mailRecipients(array $addresses): string
    {
        if (config('larabug.requests.capture_mail_recipients', false)) {
            return implode(',', $addresses);
        }

        $domains = [];

        foreach ($addresses as $address) {
            $at = strrpos($address, '@');

            if ($at !== false) {
                $domains[$this->normalisedDomain(substr($address, $at + 1))] = true;
            }
        }

        return implode(',', array_keys($domains));
    }

    private function normalisedDomain(string $domain): string
    {
        return strtolower(trim($domain, " \t\n\r\0\x0B>"));
    }

    /**
     * Zero when the sending event was never seen for this message: a mailer
     * that only fires sent, or a send that threw before the mark was read back.
     */
    private function mailDuration(object $message): float
    {
        $key = spl_object_id($message);

        if (! isset($this->mailStartedAt[$key])) {
            return 0.0;
        }

        $duration = round((microtime(true) - $this->mailStartedAt[$key]) * 1000, 3);

        unset($this->mailStartedAt[$key]);

        return $duration;
    }

    /**
     * Record a mailable that was queued rather than sent inline, read off the
     * mailable the job carries: there is no message yet, the send is a worker
     * away. Recipients are filled in by queue time, so counts and domains are
     * known; the subject often is not, since an envelope resolves it at
     * render, and the duration cannot be, so both are left for the send that
     * is not ours to see.
     */
    private function recordQueuedMail(mixed $mailable): void
    {
        if (! is_object($mailable)) {
            return;
        }

        $to = $this->mailableAddresses($mailable, 'to');
        $cc = $this->mailableAddresses($mailable, 'cc');
        $bcc = $this->mailableAddresses($mailable, 'bcc');

        $this->monitor->recordMail([
            'mailable' => get_class($mailable),
            'subject' => (string) ($mailable->subject ?? ''),
            'to_count' => count($to),
            'cc_count' => count($cc),
            'bcc_count' => count($bcc),
            'recipient_domains' => $this->mailRecipients(array_merge($to, $cc, $bcc)),
            'queued' => 1,
            'duration_ms' => 0.0,
        ]);
    }

    /**
     * The addresses in one of a mailable's recipient lists. A Mailable holds
     * its to, cc and bcc as public arrays of ['name' => ..., 'address' => ...],
     * a different shape from the getters a sent message exposes.
     *
     * @return array<int, string>
     */
    private function mailableAddresses(object $mailable, string $property): array
    {
        $recipients = $mailable->{$property} ?? [];

        if (! is_array($recipients)) {
            return [];
        }

        $addresses = [];

        foreach ($recipients as $recipient) {
            if (is_array($recipient) && isset($recipient['address'])) {
                $addresses[] = (string) $recipient['address'];

                continue;
            }

            if (is_string($recipient) && $recipient !== '') {
                $addresses[] = $recipient;
            }
        }

        return $addresses;
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
            'notification' => is_object($notification) ? $this->normalisedClass(get_class($notification)) : '',
            'channel' => (string) ($event->channel ?? ''),
            // The type, never the notifiable itself: the class is diagnostic and
            // the id is who got notified, which is not ours to keep.
            'notifiable_type' => is_object($notifiable) ? $this->normalisedClass(get_class($notifiable)) : '',
            'success' => $success,
        ]);
    }

    /**
     * An on-the-fly notification is an anonymous class, and get_class returns
     * its file path after a null byte; the part before it is the only stable
     * name it has.
     */
    private function normalisedClass(string $class): string
    {
        $nul = strpos($class, "\0");

        return $nul === false ? $class : substr($class, 0, $nul);
    }

    /**
     * Whether a mail event is a notification going out over the mail channel:
     * Laravel stamps the notification on the event data, and the notification
     * path already records it, so the mail path leaves it alone.
     */
    private function isNotificationMail(object $event): bool
    {
        return is_array($event->data ?? null) && isset($event->data['__laravel_notification']);
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
        try {
            $callback();
        } catch (Throwable) {
            // Never let instrumentation surface in the application's own stack.
        }
    }
}
