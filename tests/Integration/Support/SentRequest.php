<?php

namespace LaraBug\Tests\Integration\Support;

use Psr\Http\Message\RequestInterface;

/**
 * One request the package handed to Guzzle, decoded.
 *
 * The package's entire observable behaviour is the HTTP calls it makes, so
 * this is the thing the integration tests assert against.
 */
class SentRequest
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly array $payload,
        public readonly array $options,
    ) {
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function from(RequestInterface $request, array $options): self
    {
        $decoded = json_decode((string) $request->getBody(), true);

        return new self(
            $request->getMethod(),
            (string) $request->getUri(),
            array_map(
                fn (array $values): string => implode(', ', $values),
                $request->getHeaders()
            ),
            is_array($decoded) ? $decoded : [],
            $options,
        );
    }

    /**
     * What kind of message this is. Reports are the one payload with no type,
     * because they were here before anything else was.
     */
    public function type(): string
    {
        return $this->payload['type'] ?? 'report';
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $header => $value) {
            if (strcasecmp($header, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Read a value out of the payload with dot notation.
     */
    public function payload(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->payload;
        }

        $value = $this->payload;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
