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

    /** @var array<int, float> Send-start marks, in seconds, keyed by message. */
    protected $mailStartedAt = [];

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

        // Paired: the sending event only starts a timer, the sent event is what
        // records the message. A send that throws never reaches sent and leaves
        // only a mark that the next send overwrites.
        $events->listen('Illuminate\Mail\Events\MessageSending', [$this, 'onMailSending']);
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
        $this->guard(function () use ($event) {
            $this->monitor->increment('jobs_queued');

            // A queued mailable is a job whose payload is the mailable itself,
            // and its send happens a worker away where no request is being
            // recorded. Caught here, at dispatch, it is counted against the
            // request that queued it or it is never seen inside one at all.
            $job = $event->job ?? null;

            if ($job instanceof \Illuminate\Mail\SendQueuedMailable) {
                $this->recordQueuedMail($job->mailable ?? null);
            }
        });
    }

    public function onMailSending($event): void
    {
        $this->guard(function () use ($event) {
            $message = $event->message ?? null;

            if ($message === null) {
                return;
            }

            $this->mailStartedAt[spl_object_id($message)] = microtime(true);
        });
    }

    public function onMailSent($event): void
    {
        $this->guard(function () use ($event) {
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
     * The class of the mailable being sent, when there is one.
     *
     * Neither mail event carries it, so it is read off the call stack instead:
     * a Mailable's send() is always a frame below the event that fires inside
     * it. Empty for mail sent without a mailable — Mail::raw() and the like —
     * where the subject is the only name a message has.
     */
    private function mailableClass(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 50) as $frame) {
            $object = $frame['object'] ?? null;

            if ($object instanceof \Illuminate\Mail\Mailable) {
                return get_class($object);
            }
        }

        return '';
    }

    private function mailSubject($message): string
    {
        if (! method_exists($message, 'getSubject')) {
            return '';
        }

        return (string) $message->getSubject();
    }

    /**
     * One address list off a message, across mail engines. Symfony's Email has
     * the getters and returns Address objects; the older Swift message had the
     * same getter names and returned an [address => name] map. A version without
     * the getter simply contributes nobody.
     *
     * @return array<int|string, mixed>
     */
    private function recipients($message, string $getter): array
    {
        if (! method_exists($message, $getter)) {
            return [];
        }

        return (array) $message->{$getter}();
    }

    /**
     * The address strings in a recipient list, whichever engine produced it.
     * Symfony hands over Address objects; Swift keyed the address and put the
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
     * What is stored for who a message went to: the domains it reached, deduped,
     * and never the addresses themselves. The domain is the diagnostic part — a
     * bounce is a bounce to gmail.com, not to a person — and the local part is
     * the customer data the whole request position rests on not keeping.
     *
     * An application that has decided its recipients are safe to store opts the
     * full addresses in, the same shape the payload capture takes.
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
     * How long the send took, when the sending event was seen for this same
     * message. Zero when it was not: a mailer that only fires sent, or a message
     * whose sending threw before the mark was read back.
     */
    private function mailDuration($message): float
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
     * Record a mailable that was queued rather than sent inline.
     *
     * Read off the mailable the job carries, not off a message: there is no
     * message yet, the send is a worker away. Its recipients are already filled
     * in by the time it is queued, so the counts and domains are known; the
     * subject often is not, since an envelope resolves it at render, and the
     * duration cannot be, so both are left for the send that is not ours to see.
     *
     * @param  mixed  $mailable
     */
    private function recordQueuedMail($mailable): void
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
     * The addresses in one of a mailable's recipient lists. A Mailable holds its
     * to, cc and bcc as public arrays of ['name' => ..., 'address' => ...], which
     * is a different shape from the message getters a sent message exposes.
     *
     * @param  mixed  $mailable
     * @return array<int, string>
     */
    private function mailableAddresses($mailable, string $property): array
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
