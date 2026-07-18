<?php

namespace LaraBug\Logger;

use DateTimeInterface;
use JsonSerializable;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Throwable;

/**
 * Ships log lines to LaraBug.
 *
 * Separate from LaraBugHandler on purpose. That one exists to turn a logged
 * Throwable into an exception report and sits at ERROR; this one is interested
 * in the ordinary lines around an error, so it runs at a much lower level and
 * must stay cheap enough to sit in a request's hot path.
 */
class LaraBugLogHandler extends AbstractProcessingHandler
{
    /** @var LogBuffer */
    protected $buffer;

    /** @var array */
    protected $config;

    /**
     * @param LogBuffer $buffer
     * @param array $config
     * @param int $level
     * @param bool $bubble
     */
    public function __construct(LogBuffer $buffer, array $config = [], $level = Logger::DEBUG, bool $bubble = true)
    {
        $this->buffer = $buffer;
        $this->config = $config;

        parent::__construct($level, $bubble);
    }

    /**
     * @param array $record
     */
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
     *
     * @param array|\ArrayAccess $record
     * @return array
     */
    protected function toPayload($record): array
    {
        $context = $this->arrayValue($record, 'context');

        // The exception object is what LaraBugHandler reports separately. Left
        // in place it would be the single largest thing in the payload, and the
        // interesting parts of it are already on the exception report.
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
            'trace_id' => (string) $this->correlation($record, 'trace_id'),
            'exception_id' => (string) $this->correlation($record, 'exception_id'),
            'environment' => (string) ($this->config['environment'] ?? ''),
            'release' => (string) ($this->config['release'] ?? ''),
            'user_identifier' => (string) $this->correlation($record, 'user_identifier'),
        ];
    }

    /**
     * @param array|\ArrayAccess $record
     * @return string
     */
    protected function timestamp($record): string
    {
        $datetime = $this->value($record, 'datetime');

        if ($datetime instanceof DateTimeInterface) {
            return $datetime->format('Y-m-d\TH:i:s.vP');
        }

        return date('Y-m-d\TH:i:s.000P');
    }

    /**
     * @param array|\ArrayAccess $record
     * @return string
     */
    protected function level($record): string
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

    /**
     * Correlation ids travel in whichever bag the application happened to use,
     * so both are checked rather than picking a side.
     *
     * @param array|\ArrayAccess $record
     * @param string $key
     * @return string
     */
    protected function correlation($record, string $key): string
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
     * an object graph per line is how a logging integration turns into an
     * outage.
     *
     * @param array $values
     * @param int $depth
     * @return array
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

    /**
     * @param mixed $value
     * @param int $depth
     * @return mixed
     */
    protected function normalizeValue($value, int $depth)
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
                'class' => get_class($value),
                'message' => $this->truncate($value->getMessage()),
                'file' => $value->getFile().':'.$value->getLine(),
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
                return get_class($value);
            }
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return $this->truncate((string) $value);
        }

        return is_object($value) ? get_class($value) : '[resource]';
    }

    /**
     * @param string $value
     * @return string
     */
    protected function truncate(string $value): string
    {
        $limit = 2000;

        return strlen($value) > $limit ? substr($value, 0, $limit).'…' : $value;
    }

    /**
     * @param array|\ArrayAccess $record
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function value($record, string $key, $default = null)
    {
        if (is_array($record)) {
            return array_key_exists($key, $record) ? $record[$key] : $default;
        }

        if ($record instanceof \ArrayAccess) {
            return isset($record[$key]) ? $record[$key] : $default;
        }

        return $default;
    }

    /**
     * @param array|\ArrayAccess $record
     * @param string $key
     * @return array
     */
    protected function arrayValue($record, string $key): array
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
