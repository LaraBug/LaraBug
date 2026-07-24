<?php

namespace LaraBug\Logger;

use Throwable;
use ArrayAccess;
use Monolog\Logger;
use JsonSerializable;
use DateTimeInterface;
use LaraBug\Requests\TraceContext;
use Monolog\Handler\AbstractProcessingHandler;

/**
 * Ships log lines to LaraBug.
 *
 * Separate from LaraBugHandler on purpose: that one turns a logged Throwable
 * into an exception report at ERROR level, while this one ships the ordinary
 * lines around an error and must stay cheap enough for a request's hot path.
 */
class LaraBugLogHandler extends AbstractProcessingHandler
{
    public function __construct(
        protected readonly LogBuffer $buffer,
        protected readonly array $config = [],
        $level = Logger::DEBUG,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write($record): void
    {
        if (! $this->buffer->enabled()) {
            return;
        }

        try {
            $this->buffer->add($this->toPayload($record));
        } catch (Throwable $e) {
            // Never let shipping a log line break the call that wrote it.
        }
    }

    /**
     * Monolog 3 hands over a LogRecord object rather than an array, but it
     * implements ArrayAccess over the same keys, so one accessor covers 1, 2
     * and 3 without branching on the version.
     */
    protected function toPayload(array|ArrayAccess $record): array
    {
        $context = $this->arrayValue($record, 'context');

        // The exception object is what LaraBugHandler reports separately; left in
        // place it would be the single largest thing in the payload.
        unset($context['exception']);

        return [
            'timestamp' => $this->timestamp($record),
            'level' => $this->level($record),
            'channel' => (string) $this->value($record, 'channel', ''),
            'message' => (string) $this->value($record, 'message', ''),
            'context' => $this->normalize($context),
            // Laravel's Context facade and every Monolog processor write here,
            // not into context, so dropping it would lose most of what makes a
            // line useful.
            'extra' => $this->normalize($this->arrayValue($record, 'extra')),
            // Falls back to the ambient trace so lines are joined to the request
            // or job that wrote them even when the app never sets a trace id.
            'trace_id' => $this->traceId($record),
            'exception_id' => (string) $this->correlation($record, 'exception_id'),
            'environment' => (string) ($this->config['environment'] ?? ''),
            'release' => (string) ($this->config['release'] ?? ''),
            'user_identifier' => (string) $this->correlation($record, 'user_identifier'),
        ];
    }

    protected function timestamp(array|ArrayAccess $record): string
    {
        $datetime = $this->value($record, 'datetime');

        if ($datetime instanceof DateTimeInterface) {
            return $datetime->format('Y-m-d\TH:i:s.vP');
        }

        return date('Y-m-d\TH:i:s.000P');
    }

    protected function level(array|ArrayAccess $record): string
    {
        $name = $this->value($record, 'level_name');

        // Monolog 3 replaced the int level with a Level enum, whose ->name is
        // "Warning" rather than the "WARNING" earlier versions produced.
        if (! is_string($name)) {
            $level = $this->value($record, 'level');
            $name = is_object($level) && isset($level->name) ? $level->name : 'info';
        }

        return strtolower($name);
    }

    protected function traceId(array|ArrayAccess $record): string
    {
        $supplied = $this->correlation($record, 'trace_id');

        if ($supplied !== '') {
            return $supplied;
        }

        try {
            return TraceContext::id();
        } catch (Throwable $e) {
            // A line that cannot be correlated is still a line worth shipping.
            return '';
        }
    }

    /**
     * Correlation ids travel in whichever bag the application happened to use,
     * so both are checked rather than picking a side.
     */
    protected function correlation(array|ArrayAccess $record, string $key): string
    {
        foreach (['context', 'extra'] as $bag) {
            $values = $this->arrayValue($record, $bag);

            if (isset($values[$key]) && is_scalar($values[$key])) {
                return (string) $values[$key];
            }
        }

        return '';
    }

    /**
     * Reduce a context bag to something that survives json_encode.
     *
     * Bounded on purpose: log context routinely holds whole models, and sending
     * an object graph per line is how a logging integration turns into an outage.
     */
    protected function normalize(array $values, int $depth = 0): array
    {
        $normalized = [];
        $max = isset($this->config['logs']['max_context_keys'])
            ? (int) $this->config['logs']['max_context_keys']
            : 50;

        foreach ($values as $key => $value) {
            if (count($normalized) >= $max) {
                $normalized['_truncated'] = true;

                break;
            }

            $normalized[$key] = $this->normalizeValue($value, $depth);
        }

        return $normalized;
    }

    protected function normalizeValue(mixed $value, int $depth): mixed
    {
        if (is_scalar($value) || $value === null) {
            return is_string($value) ? $this->truncate($value) : $value;
        }

        if ($depth >= 3) {
            return is_array($value) ? '[array]' : '[object]';
        }

        if (is_array($value)) {
            return $this->normalize($value, $depth + 1);
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof Throwable) {
            return [
                'class' => $value::class,
                'message' => $this->truncate($value->getMessage()),
                'file' => "{$value->getFile()}:{$value->getLine()}",
            ];
        }

        if ($value instanceof JsonSerializable) {
            $data = $value->jsonSerialize();

            return is_array($data) ? $this->normalize($data, $depth + 1) : $this->normalizeValue($data, $depth + 1);
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            try {
                return $this->normalize((array) $value->toArray(), $depth + 1);
            } catch (Throwable $e) {
                return $value::class;
            }
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return $this->truncate((string) $value);
        }

        return is_object($value) ? $value::class : '[resource]';
    }

    protected function truncate(string $value): string
    {
        $limit = 2000;

        return strlen($value) > $limit ? substr($value, 0, $limit).'…' : $value;
    }

    protected function value(array|ArrayAccess $record, string $key, mixed $default = null): mixed
    {
        if (is_array($record)) {
            return array_key_exists($key, $record) ? $record[$key] : $default;
        }

        return $record[$key] ?? $default;
    }

    protected function arrayValue(array|ArrayAccess $record, string $key): array
    {
        $value = $this->value($record, $key, []);

        return is_array($value) ? $value : [];
    }

    public function close(): void
    {
        $this->buffer->flush();

        parent::close();
    }
}
