<?php

namespace LaraBug\Console;

use Illuminate\Contracts\Events\Dispatcher;
use LaraBug\Requests\TraceContext;
use Throwable;

/**
 * The events that fill in a scheduled task record.
 *
 * Subscribed only when scheduled task tracking is on. A scheduled task is its
 * own execution context, and a scheduled command run is attributed here rather
 * than to commands: the in-flight counter this keeps is what the command
 * listener reads to bow out while a task is running, so the run is counted once.
 */
class ScheduledTaskListeners
{
    /**
     * How many scheduled tasks are running right now. The command listener reads
     * this to attribute a scheduled command run to the schedule rather than to
     * commands. A counter, not a flag, because a task can, in principle, run
     * inside another.
     *
     * @var int
     */
    public static $inFlight = 0;

    /** @var ScheduledTaskBuffer */
    protected $buffer;

    /** @var array<int, float> Start marks, keyed by the task object. */
    protected $startedAt = [];

    public function __construct(ScheduledTaskBuffer $buffer)
    {
        $this->buffer = $buffer;
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen('Illuminate\Console\Events\ScheduledTaskStarting', [$this, 'onScheduledTaskStarting']);
        $events->listen('Illuminate\Console\Events\ScheduledTaskFinished', [$this, 'onScheduledTaskFinished']);
        $events->listen('Illuminate\Console\Events\ScheduledTaskFailed', [$this, 'onScheduledTaskFailed']);
        $events->listen('Illuminate\Console\Events\ScheduledTaskSkipped', [$this, 'onScheduledTaskSkipped']);
    }

    public function onScheduledTaskStarting($event): void
    {
        $this->guard(function () use ($event) {
            self::$inFlight++;

            // Each task is its own unit of work, so each gets its own trace, the
            // same as a queued job or a command.
            TraceContext::reset();

            $task = $event->task ?? null;

            if (is_object($task)) {
                $this->startedAt[spl_object_id($task)] = microtime(true);
            }
        });
    }

    public function onScheduledTaskFinished($event): void
    {
        $this->record($event, 'ran');
    }

    public function onScheduledTaskFailed($event): void
    {
        $this->record($event, 'failed');
    }

    public function onScheduledTaskSkipped($event): void
    {
        // A skipped task never started, so the in-flight counter was not raised
        // for it and nothing needs releasing here.
        $this->guard(function () use ($event) {
            $this->buffer->add($this->toRecord($event->task ?? null, 'skipped', 0.0));
        });
    }

    /**
     * @param  mixed  $event
     */
    protected function record($event, string $status): void
    {
        $this->guard(function () use ($event, $status) {
            if (self::$inFlight > 0) {
                self::$inFlight--;
            }

            $task = $event->task ?? null;
            $duration = $this->duration($task, $event);

            $this->buffer->add($this->toRecord($task, $status, $duration));
        });
    }

    /**
     * @param  mixed  $task
     * @return array<string, mixed>
     */
    protected function toRecord($task, string $status, float $duration): array
    {
        return [
            'task' => $this->taskName($task),
            'expression' => is_object($task) ? (string) ($task->expression ?? '') : '',
            'status' => $status,
            'duration_ms' => round($duration, 3),
            'trace_id' => TraceContext::id(),
            'without_overlapping' => (is_object($task) && ! empty($task->withoutOverlapping)) ? 1 : 0,
            'environment' => (string) config('app.env'),
            'release' => (string) config('larabug.project_version', ''),
            'host' => (string) gethostname(),
            'ran_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    /**
     * How long the task ran, from the mark its starting left. The finished event
     * also carries a runtime, but a start mark works for failed too, which does
     * not.
     *
     * @param  mixed  $task
     * @param  mixed  $event
     */
    protected function duration($task, $event): float
    {
        if (is_object($task)) {
            $key = spl_object_id($task);

            if (isset($this->startedAt[$key])) {
                $duration = (microtime(true) - $this->startedAt[$key]) * 1000;

                unset($this->startedAt[$key]);

                return $duration;
            }
        }

        // A finished event carries the runtime in seconds; fall back to it.
        if (is_object($event) && isset($event->runtime)) {
            return (float) $event->runtime * 1000;
        }

        return 0.0;
    }

    /**
     * A readable name for a task. A scheduled command has its command string, a
     * closure has its description or nothing worth a name.
     *
     * @param  mixed  $task
     */
    protected function taskName($task): string
    {
        if (! is_object($task)) {
            return '';
        }

        if (method_exists($task, 'getSummaryForDisplay')) {
            $summary = (string) $task->getSummaryForDisplay();

            if ($summary !== '') {
                return $this->cleanCommand($summary);
            }
        }

        if (! empty($task->description)) {
            return (string) $task->description;
        }

        if (! empty($task->command)) {
            return $this->cleanCommand((string) $task->command);
        }

        return 'Closure';
    }

    /**
     * Strip the php binary and artisan prelude a scheduled command's string
     * carries, leaving the command as it would be typed.
     */
    protected function cleanCommand(string $command): string
    {
        if (preg_match("/artisan'?\s+(.*)$/", $command, $matches)) {
            return trim($matches[1]);
        }

        return $command;
    }

    protected function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            // Never let instrumentation surface in the application's own output.
        }
    }
}
