<?php

namespace LaraBug\Mail;

use Throwable;
use Illuminate\Mail\Mailable;
use LaraBug\Support\ClassName;
use LaraBug\Requests\RequestMonitor;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Notifications\SendQueuedNotifications;

/**
 * Outgoing mail, recorded against the execution that sent it.
 *
 * Mail used to be a request stream only, and real applications send almost
 * none of theirs from a request: a queued mailable, a queued notification and
 * a nightly digest all go out from a worker or a command. So every message is
 * handed to the innermost execution still open when it was sent: a job, else
 * the command, else the request. A queue worker is itself a command, and a job
 * on the sync connection runs inside the request that dispatched it; in both
 * cases the job is innermost, and the message is recorded there and only
 * there.
 *
 * Jobs and commands open and close their executions here themselves, because
 * their own listeners build the records the mail has to end up in. The request
 * is the fallback, and only when one is being recorded at all.
 *
 * One per process, held as a singleton, and every handler is wrapped: these
 * fire inside the customer's send, and a listener that throws would surface as
 * a mail that failed.
 */
class MailListeners
{
    /**
     * The jobs and commands in flight, innermost last, keyed by object id so
     * one can close out of order without disturbing the rest.
     *
     * @var array<int, ExecutionMail>
     */
    protected array $executions = [];

    /**
     * Send-start marks, keyed by the object id of the message the sending
     * event carried.
     *
     * The mailable and subject are read here rather than at the sent event
     * because a send that throws never reaches one, and a failure whose
     * mailable is empty groups with every other mailer's failures. The owner
     * is fixed here too: the send belongs to the execution it started in,
     * whatever has opened or closed by the time it is over.
     *
     * The message itself is held as well. The sent event usually carries a
     * different object (see markFor()), so the mark has to be found by what
     * the message says rather than by its id, and holding it also keeps its
     * id from being handed to a later message while the mark still stands.
     *
     * @var array<int, array{at: float, mailable: string, subject: string, queued: int, owner: RecordsMail, message: object}>
     */
    protected array $mailStartedAt = [];

    public function __construct(protected readonly ?RequestMonitor $request = null)
    {
        // A request has no close to call, so its unfinished sends are swept
        // when its record is built instead.
        $this->request?->beforeFlush(fn () => $this->flushUnfinishedMail($this->request));
    }

    public function subscribe(Dispatcher $events): void
    {
        // Paired: the sending event only starts a timer, the sent event is what
        // records the message. A send that throws never reaches sent and leaves
        // a mark that its execution's close turns into a failure.
        $events->listen(MessageSending::class, $this->onMailSending(...));
        $events->listen(MessageSent::class, $this->onMailSent(...));
    }

    /**
     * Start an execution whose mail is recorded on its own record.
     */
    public function open(): ExecutionMail
    {
        $execution = new ExecutionMail();

        $this->executions[spl_object_id($execution)] = $execution;

        return $execution;
    }

    /**
     * End an execution: its unfinished sends become failures on it, and the
     * mail sent from here on goes to whatever encloses it.
     */
    public function close(ExecutionMail $execution): void
    {
        $this->flushUnfinishedMail($execution);

        unset($this->executions[spl_object_id($execution)]);
    }

    public function onMailSending(object $event): void
    {
        $this->guard(function () use ($event) {
            $message = $event->message ?? null;

            if ($message === null) {
                return;
            }

            $owner = $this->owner();

            if ($owner === null) {
                return;
            }

            [$mailable, $queued] = $this->origin($event);

            $this->mailStartedAt[spl_object_id($message)] = [
                'at' => microtime(true),
                'mailable' => $mailable,
                'subject' => $this->mailSubject($message),
                'queued' => $queued,
                'owner' => $owner,
                'message' => $message,
            ];
        });
    }

    public function onMailSent(object $event): void
    {
        $this->guard(function () use ($event) {
            $message = $event->message ?? null;

            // Read off the mark where there is one: the sending event already
            // walked the stack for these, and walking it twice per message
            // buys nothing. A sent event with no sending before it, which is
            // mostly a mailer under test, still reads them live.
            $key = $message === null ? null : $this->markFor($message);

            $mark = $key === null ? null : $this->mailStartedAt[$key];

            // Taken off as soon as it is found: a mark left standing is a send
            // that failed, and this one did not.
            if ($key !== null) {
                unset($this->mailStartedAt[$key]);
            }

            $owner = $mark['owner'] ?? $this->owner();

            if ($owner === null) {
                return;
            }

            if ($message === null) {
                // A mailer that fired the event without a message still sent
                // one; keep the tile honest even when there is nothing to detail.
                $owner->recordMail(['failed' => 0]);

                return;
            }

            [$mailable, $queued] = $mark === null
                ? $this->origin($event)
                : [$mark['mailable'], $mark['queued']];

            $owner->recordMail(MailEntry::sent(
                $message,
                $mailable,
                $mark['subject'] ?? $this->mailSubject($message),
                $queued,
                $this->mailDuration($mark),
            ));
        });
    }

    /**
     * Every send an execution started and never finished, as a failed message.
     *
     * Laravel fires no event for a send that throws: `MessageSending` happens,
     * the transport raises, and `MessageSent` never comes. What is left is the
     * mark, so the marks still standing when an execution ends are exactly the
     * sends that failed in it.
     *
     * Clearing as it sweeps makes a second call a no-op, which is what keeps a
     * record built twice from doubling the failures.
     */
    public function flushUnfinishedMail(RecordsMail $owner): void
    {
        $this->guard(function () use ($owner) {
            foreach ($this->mailStartedAt as $key => $mark) {
                if ($mark['owner'] !== $owner) {
                    continue;
                }

                unset($this->mailStartedAt[$key]);

                $owner->recordMail(MailEntry::unfinished(
                    $mark['mailable'],
                    $mark['subject'],
                    $mark['queued'],
                    round((microtime(true) - $mark['at']) * 1000, 3),
                ));
            }
        });
    }

    /**
     * The innermost execution still open, or the request when none is. Null
     * when nothing is recording mail: a console process outside any job or
     * command, where there is no request to fall back on.
     */
    protected function owner(): ?RecordsMail
    {
        if ($this->executions !== []) {
            return end($this->executions);
        }

        return $this->request;
    }

    /**
     * Which mailable a message is, and whether it was queued to get here.
     *
     * A notification over the mail channel is named after the notification:
     * Laravel stamps it on the event data, and it is the name the application
     * knows the message by, whether the notification built a MailMessage or
     * returned a Mailable of its own.
     *
     * Neither mail event carries the mailable otherwise, so it is read off the
     * call stack instead: a Mailable's send() is always a frame below the event
     * that fires inside it. Empty for mail sent without a mailable, Mail::raw()
     * and the like, where the subject is the only name a message has.
     *
     * Queued is read off the same stack: a message is queued when the job
     * delivering it is the one Laravel wraps a queued mailable or notification
     * in. The stack rather than the notification's own `queued` stamp, which
     * says the class implements ShouldQueue and stays true for a sendNow().
     *
     * @return array{0: string, 1: int}
     */
    protected function origin(object $event): array
    {
        $data = $event->data ?? null;

        $notification = is_array($data) && is_string($data['__laravel_notification'] ?? null)
            ? ClassName::normalised($data['__laravel_notification'])
            : null;

        $mailable = '';
        $queued = 0;

        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 80) as $frame) {
            $object = $frame['object'] ?? null;

            if ($mailable === '' && $object instanceof Mailable) {
                $mailable = ClassName::normalised(get_class($object));
            }

            if ($object instanceof SendQueuedMailable || $object instanceof SendQueuedNotifications) {
                $queued = 1;

                break;
            }
        }

        return [$notification ?? $mailable, $queued];
    }

    protected function mailSubject(object $message): string
    {
        if (! method_exists($message, 'getSubject')) {
            return '';
        }

        return (string) $message->getSubject();
    }

    /**
     * The mark the sending event left for the message a sent event carries,
     * as its key, or null when there is none.
     *
     * The sent event rarely carries the object the sending event did. Every
     * transport that extends Symfony's AbstractTransport, which is SMTP,
     * Postmark, Mailgun, SES and the rest, sends `clone $message`, and
     * Laravel's sent event hands over that clone. Keyed by object id alone,
     * no such send ever found its mark: it was recorded as sent, and its
     * mark, still standing at the close, recorded it a second time as a
     * failure. Only the array and log transports send the object itself.
     *
     * So the id is tried first, and otherwise the newest mark whose message
     * has the same class, subject and addresses. Those are compared on the
     * held message as it is now rather than as it was at the sending event,
     * so a later sending listener that rewrote the recipients (a staging
     * redirect, say) is matched too: the transport cloned it after that.
     * Headers as a whole are not compared, because some transports stamp
     * their own on the clone once it is sent (SES its message id, Resend its
     * email id).
     *
     * Newest first, because a send that failed leaves its mark behind, and a
     * retry of the very same message must not take the failure's mark and
     * leave its own to be counted as failed instead.
     *
     * Nothing is added to the message to pair the two. A header would pair
     * them exactly, but it would go out in every customer's mail, in the raw
     * source any recipient can read. Two identical messages open at once are
     * the only thing this can confuse, and they are indistinguishable anyway:
     * either way one is sent and one is not.
     */
    protected function markFor(object $message): ?int
    {
        $key = spl_object_id($message);

        if (($this->mailStartedAt[$key]['message'] ?? null) === $message) {
            return $key;
        }

        $identity = $this->mailIdentity($message);

        foreach (array_reverse($this->mailStartedAt, true) as $key => $mark) {
            if ($this->mailIdentity($mark['message']) === $identity) {
                return $key;
            }
        }

        return null;
    }

    /**
     * What a message says, as far as a transport cannot change it: the class,
     * the subject and every address on it.
     */
    protected function mailIdentity(object $message): string
    {
        $parts = [get_class($message), $this->mailSubject($message)];

        foreach (['getFrom', 'getTo', 'getCc', 'getBcc', 'getReplyTo'] as $getter) {
            $addresses = method_exists($message, $getter) ? (array) $message->{$getter}() : [];

            $parts[] = implode(',', array_map(
                fn ($address) => is_object($address) && method_exists($address, 'getAddress') ? $address->getAddress() : (string) $address,
                $addresses,
            ));
        }

        return implode("\n", $parts);
    }

    /**
     * Zero when the sending event was never seen for this message: a mailer
     * that only fires sent.
     *
     * @param  array{at: float}|null  $mark
     */
    protected function mailDuration(?array $mark): float
    {
        if ($mark === null) {
            return 0.0;
        }

        return round((microtime(true) - $mark['at']) * 1000, 3);
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
