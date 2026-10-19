<?php

namespace LaraBug\Logger;

use Throwable;
use ArrayAccess;
use ErrorException;
use Monolog\Logger;

/**
 * Ships what Laravel writes to its deprecations channel, once per deprecation
 * rather than once per occurrence.
 *
 * Laravel words a deprecation differently depending on its version and on
 * `logging.deprecations.trace`, and puts the file and line inside the message.
 * They are taken back out, so the same deprecation always reads the same and
 * gets the same fingerprint wherever the application is deployed.
 */
class LaraBugDeprecationHandler extends LaraBugLogHandler
{
    public function __construct(
        LogBuffer $buffer,
        protected readonly DeprecationBuffer $deprecations,
        array $config = [],
        $level = Logger::DEBUG,
        bool $bubble = true,
    ) {
        parent::__construct($buffer, $config, $level, $bubble);
    }

    protected function write($record): void
    {
        if (! $this->buffer->enabled()) {
            return;
        }

        try {
            $deprecation = $this->deprecation($record);

            $this->deprecations->add(
                $deprecation['fingerprint'],
                fn () => $this->toDeprecationPayload($record, $deprecation),
            );
        } catch (Throwable $e) {
            // Never let shipping a deprecation break the call that raised it.
        }
    }

    /**
     * @return array{message: string, file: string, line: int, source: string, trace: ?string, fingerprint: string}
     */
    protected function deprecation(array|ArrayAccess $record): array
    {
        $deprecation = $this->parse($record);

        $deprecation['file'] = $this->relativePath($deprecation['file']);
        $deprecation['fingerprint'] = md5("{$deprecation['message']}|{$deprecation['file']}|{$deprecation['line']}");

        return $deprecation;
    }

    /**
     * @return array{message: string, file: string, line: int, source: string, trace: ?string}
     */
    protected function parse(array|ArrayAccess $record): array
    {
        $message = (string) $this->value($record, 'message', '');
        $exception = $this->arrayValue($record, 'context')['exception'] ?? null;

        // With the trace on, Laravel passes the message as raised and the
        // exception it built from it, which knows the file and the line.
        if ($exception instanceof ErrorException) {
            return [
                'message' => $message,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'source' => 'php',
                'trace' => $exception->getTraceAsString(),
            ];
        }

        // With the trace on, earlier versions of Laravel logged the exception
        // cast to a string, its trace included.
        if (preg_match('/^ErrorException: (.*) in (.+):(\d+)\RStack trace:\R(.*)$/s', $message, $matches)) {
            return [
                'message' => $matches[1],
                'file' => $matches[2],
                'line' => (int) $matches[3],
                'source' => 'php',
                'trace' => $matches[4],
            ];
        }

        if (preg_match('/^(.*) in (.+) on line (\d+)$/s', $message, $matches)) {
            return [
                'message' => $matches[1],
                'file' => $matches[2],
                'line' => (int) $matches[3],
                'source' => 'php',
                'trace' => null,
            ];
        }

        // Not raised by PHP and handed over by Laravel's error handler, but
        // written to the deprecations channel by code that called it directly.
        [$file, $line] = $this->caller();

        return [
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'source' => 'laravel',
            'trace' => null,
        ];
    }

    /**
     * @param  array{message: string, file: string, line: int, source: string, trace: ?string, fingerprint: string}  $deprecation
     */
    protected function toDeprecationPayload(array|ArrayAccess $record, array $deprecation): array
    {
        $payload = $this->toPayload($record);

        // Set here rather than read from the record: inside a stack, the record
        // carries the name of the stack, not the name of our channel.
        $payload['level'] = 'warning';
        $payload['channel'] = 'deprecations';
        $payload['message'] = $deprecation['message'];

        if ($deprecation['trace'] !== null) {
            $payload['context']['trace'] = $this->truncate($this->withoutBasePath($deprecation['trace']));
        }

        $payload['extra']['larabug_deprecation'] = [
            'fingerprint' => $deprecation['fingerprint'],
            'file' => $deprecation['file'],
            'line' => $deprecation['line'],
            'count' => 1,
            'source' => $deprecation['source'],
        ];

        return $payload;
    }

    /**
     * The first frame outside the logger, which is the code that wrote the line.
     *
     * @return array{0: string, 1: int}
     */
    protected function caller(): array
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = str_replace('\\', '/', $frame['file'] ?? '');

            if ($file === '' || $this->isInsideTheLogger($file)) {
                continue;
            }

            return [$file, $frame['line'] ?? 0];
        }

        return ['', 0];
    }

    protected function isInsideTheLogger(string $file): bool
    {
        if (str_starts_with($file, str_replace('\\', '/', dirname(__DIR__)))) {
            return true;
        }

        foreach (['/monolog/monolog/', '/psr/log/', '/Illuminate/Log/', '/Illuminate/Support/Facades/', '/Illuminate/Foundation/helpers.php'] as $logger) {
            if (str_contains($file, $logger)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Relative to the application, so a deprecation keeps its fingerprint from
     * one release directory to the next. A file outside the application keeps
     * its path from `vendor/` on if it has one, else only its name.
     */
    protected function relativePath(string $file): string
    {
        $file = str_replace('\\', '/', $file);

        if ($file === '') {
            return '';
        }

        $base = $this->basePath();

        if (str_starts_with($file, $base)) {
            return substr($file, strlen($base));
        }

        $vendor = strpos($file, '/vendor/');

        if ($vendor !== false) {
            return substr($file, $vendor + 1);
        }

        return basename($file);
    }

    protected function withoutBasePath(string $trace): string
    {
        return str_replace($this->basePath(), '', str_replace('\\', '/', $trace));
    }

    protected function basePath(): string
    {
        return rtrim(str_replace('\\', '/', base_path()), '/').'/';
    }
}
