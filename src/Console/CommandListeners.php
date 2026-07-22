<?php

namespace LaraBug\Console;

use Illuminate\Contracts\Events\Dispatcher;
use LaraBug\Requests\TraceContext;
use Throwable;

/**
 * The events that fill in a command record.
 *
 * Subscribed only when command tracking is on. A command is its own execution
 * context, neither a request nor a job, so it carries its own trace and its own
 * buffer. The handlers are stacked rather than keyed on a single current
 * command, because a command can call another (Artisan::call), and a finish has
 * to match the start it belongs to.
 */
class CommandListeners
{
    /** @var CommandBuffer */
    protected $buffer;

    /** @var array<int, array<string, mixed>|null> The commands in flight. */
    protected $stack = [];

    public function __construct(CommandBuffer $buffer)
    {
        $this->buffer = $buffer;
    }

    /**
     * Registered one at a time rather than by returning a map, the same as the
     * request listeners: this package supports Laravel 6, whose dispatcher does
     * not read a subscribe map.
     */
    public function subscribe(Dispatcher $events): void
    {
        $events->listen('Illuminate\Console\Events\CommandStarting', [$this, 'onCommandStarting']);
        $events->listen('Illuminate\Console\Events\CommandFinished', [$this, 'onCommandFinished']);
    }

    public function onCommandStarting($event): void
    {
        $this->guard(function () use ($event) {
            $command = (string) ($event->command ?? '');

            if ($command === '' || $this->ignored($command)) {
                // A null frame keeps the finish handler's stack balanced without
                // recording the ignored command.
                $this->stack[] = null;

                return;
            }

            // Each command is its own unit of work, so each gets its own trace,
            // the same as a queued job. A console process that runs several in a
            // row would otherwise stamp them all with the first one's id.
            TraceContext::reset();

            $this->stack[] = [
                'command' => $command,
                'trace_id' => TraceContext::id(),
                'started_at' => gmdate('Y-m-d H:i:s'),
                'start' => microtime(true),
            ];
        });
    }

    public function onCommandFinished($event): void
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

                // The same id any log lines and exceptions from this command
                // carry, which is the whole reason to keep it.
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
     * The arguments and options a command was given, as JSON, with the sensitive
     * ones replaced by a marker. Read off the input and guarded: the input is a
     * Symfony contract whose getters throw when the definition is not bound, and
     * a command that could not be detailed is still worth counting.
     *
     * @param  mixed  $input
     */
    protected function arguments($input): string
    {
        if (! is_object($input)) {
            return '';
        }

        $redact = array_map('strtolower', (array) config('larabug.commands.redact', []));

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
        } catch (Throwable $e) {
            return '';
        }

        return (string) json_encode($bag);
    }

    /**
     * @param  mixed  $value
     * @param  array<int, string>  $redact  lowercased needles
     * @return mixed
     */
    protected function redactValue(string $name, $value, array $redact)
    {
        $lower = strtolower($name);

        foreach ($redact as $needle) {
            if ($needle !== '' && strpos($lower, $needle) !== false) {
                return '[redacted]';
            }
        }

        return $value;
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
