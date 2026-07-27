<?php

namespace LaraBug\Console;

use Throwable;
use LaraBug\Requests\TraceContext;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;

/**
 * The events that fill in a scheduled task record.
 *
 * Subscribed only when scheduled task tracking is on. The in-flight counter it
 * keeps is what the command listener reads to bow out while a task is running,
 * so a scheduled command run is counted once, against the schedule.
 */
class ScheduledTaskListeners
{
    /**
     * How many scheduled tasks are running right now. The command listener
     * reads this to attribute a scheduled command run to the schedule rather
     * than to commands. A counter, not a flag, because a task can, in
     * principle, run inside another.
     */
    public static int $inFlight = 0;

    /** @var array<int, float> Start marks, keyed by task object id. */
    protected array $startedAt = [];

    public function __construct(protected readonly ScheduledTaskBuffer $buffer)
    {
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(ScheduledTaskStarting::class, $this->onScheduledTaskStarting(...));
        $events->listen(ScheduledTaskFinished::class, $this->onScheduledTaskFinished(...));
        $events->listen(ScheduledTaskFailed::class, $this->onScheduledTaskFailed(...));
        $events->listen(ScheduledTaskSkipped::class, $this->onScheduledTaskSkipped(...));
    }

    public function onScheduledTaskStarting(object $event): void
    {
        $this->guard(function () use ($event) {
            self::$inFlight++;

            // Each task is its own unit of work, so it gets its own trace, the
            // same as a queued job or a command.
            TraceContext::reset();

            $task = $event->task ?? null;

            if (is_object($task)) {
                $this->startedAt[spl_object_id($task)] = microtime(true);
            }
        });
    }

    public function onScheduledTaskFinished(object $event): void
    {
        $this->record($event, 'ran');
    }

    public function onScheduledTaskFailed(object $event): void
    {
        $this->record($event, 'failed');
    }

    public function onScheduledTaskSkipped(object $event): void
    {
        // A skipped task never started, so the in-flight counter was not raised
        // for it and nothing needs releasing here.
        $this->guard(function () use ($event) {
            $this->buffer->add($this->toRecord($event->task ?? null, 'skipped', 0.0));
        });
    }

    protected function record(object $event, string $status): void
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
     * @return array<string, mixed>
     */
    protected function toRecord(mixed $task, string $status, float $duration): array
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
     * How long the task ran, from the mark its starting left. The finished
     * event also carries a runtime, but a start mark works for failed too,
     * which does not.
     */
    protected function duration(mixed $task, object $event): float
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
        if (isset($event->runtime)) {
            return (float) $event->runtime * 1000;
        }

        return 0.0;
    }

    /**
     * A readable name for a task: a scheduled command has its command string,
     * a closure its description or nothing worth a name.
     */
    protected function taskName(mixed $task): string
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
        } catch (Throwable) {
            // Never let instrumentation surface in the application's own output.
        }
    }
}
