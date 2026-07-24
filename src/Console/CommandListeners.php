<?php

namespace LaraBug\Console;

use Throwable;
use LaraBug\Requests\TraceContext;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;

/**
 * The events that fill in a command record.
 *
 * Subscribed only when command tracking is on. The handlers are stacked rather
 * than keyed on a single current command, because a command can call another
 * (Artisan::call) and a finish has to match the start it belongs to.
 */
class CommandListeners
{
    /** @var array<int, array<string, mixed>|null> The commands in flight. */
    protected array $stack = [];

    public function __construct(protected readonly CommandBuffer $buffer)
    {
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(CommandStarting::class, $this->onCommandStarting(...));
        $events->listen(CommandFinished::class, $this->onCommandFinished(...));
    }

    public function onCommandStarting(object $event): void
    {
        $this->guard(function () use ($event) {
            $command = (string) ($event->command ?? '');

            // A command the scheduler runs in-process belongs to the schedule,
            // not to commands; a null frame keeps the finish handler's stack
            // balanced without recording it twice.
            if ($command === '' || $this->ignored($command) || ScheduledTaskListeners::$inFlight > 0) {
                $this->stack[] = null;

                return;
            }

            // Each command is its own unit of work, so it gets its own trace,
            // the same as a queued job.
            TraceContext::reset();

            $this->stack[] = [
                'command' => $command,
                'trace_id' => TraceContext::id(),
                'started_at' => gmdate('Y-m-d H:i:s'),
                'start' => microtime(true),
            ];
        });
    }

    public function onCommandFinished(object $event): void
    {
        $this->guard(function () use ($event) {
            if ($this->stack === []) {
                return;
            }

            $frame = array_pop($this->stack);

            if ($frame === null) {
                return;
            }

            $this->buffer->add([
                'command' => $frame['command'],
                'exit_code' => (int) ($event->exitCode ?? 0),
                'duration_ms' => round((microtime(true) - $frame['start']) * 1000, 3),
                'memory_peak_kb' => (int) round(memory_get_peak_usage(true) / 1024),

                // The same id this command's log lines and exceptions carry.
                'trace_id' => $frame['trace_id'],

                'arguments' => $this->arguments($event->input ?? null),

                'environment' => (string) config('app.env'),
                'release' => (string) config('larabug.project_version', ''),
                'host' => (string) gethostname(),
                'started_at' => $frame['started_at'],
            ]);
        });
    }

    protected function ignored(string $command): bool
    {
        foreach ((array) config('larabug.commands.ignore', []) as $pattern) {
            if (fnmatch($pattern, $command)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The arguments and options a command was given, as JSON, with sensitive
     * ones replaced by a marker. Guarded because the Symfony input getters
     * throw when the definition is not bound, and a command that could not be
     * detailed is still worth counting.
     */
    protected function arguments(mixed $input): string
    {
        if (! is_object($input)) {
            return '';
        }

        $redact = array_map(strtolower(...), (array) config('larabug.commands.redact', []));

        $bag = [];

        try {
            if (method_exists($input, 'getArguments')) {
                foreach ($input->getArguments() as $name => $value) {
                    // The command name is the record's own column, not an argument.
                    if ($name === 'command') {
                        continue;
                    }

                    $bag['arguments'][$name] = $this->redactValue((string) $name, $value, $redact);
                }
            }

            if (method_exists($input, 'getOptions')) {
                foreach ($input->getOptions() as $name => $value) {
                    $bag['options'][$name] = $this->redactValue((string) $name, $value, $redact);
                }
            }
        } catch (Throwable) {
            return '';
        }

        return (string) json_encode($bag);
    }

    /**
     * @param  array<int, string>  $redact  lowercased needles
     */
    protected function redactValue(string $name, mixed $value, array $redact): mixed
    {
        $lower = strtolower($name);

        foreach ($redact as $needle) {
            if ($needle !== '' && str_contains($lower, $needle)) {
                return '[redacted]';
            }
        }

        return $value;
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
