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
     * Send-start marks, keyed by message.
     *
     * The mailable and subject are read here rather than at the sent event
     * because a send that throws never reaches one, and a failure whose
     * mailable is empty groups with every other mailer's failures. The owner
     * is fixed here too: the send belongs to the execution it started in,
     * whatever has opened or closed by the time it is over.
     *
     * @var array<int, array{at: float, mailable: string, subject: string, queued: int, owner: RecordsMail}>
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
            $mark = $message === null ? null : ($this->mailStartedAt[spl_object_id($message)] ?? null);

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
                $this->mailDuration($message),
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
     * Zero when the sending event was never seen for this message: a mailer
     * that only fires sent, or a send that threw before the mark was read back.
     */
    protected function mailDuration(object $message): float
    {
        $key = spl_object_id($message);

        if (! isset($this->mailStartedAt[$key])) {
            return 0.0;
        }

        $duration = round((microtime(true) - $this->mailStartedAt[$key]['at']) * 1000, 3);

        unset($this->mailStartedAt[$key]);

        return $duration;
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
