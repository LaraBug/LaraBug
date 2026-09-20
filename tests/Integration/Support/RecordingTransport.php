<?php

namespace LaraBug\Tests\Integration\Support;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Assert;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Client as GuzzleClient;
use Psr\Http\Message\RequestInterface;

/**
 * A Guzzle handler that answers every call without a socket and keeps what it
 * was given.
 *
 * Everything above the socket stays real: the real service provider, the real
 * buffers, the real LaraBug\Http\Client and Guzzle's own middleware stack.
 * Only the network is replaced, because the network is the one part a test
 * cannot have. Asserting against a hand written client double instead would
 * prove the double.
 */
class RecordingTransport
{
    /** @var array<int, SentRequest> */
    protected array $sent = [];

    /** @var array<int, Response> */
    protected array $queued = [];

    public function client(): GuzzleClient
    {
        return new GuzzleClient([
            // The full default stack: http_errors, redirects and the rest run
            // exactly as they do in an application.
            'handler' => HandlerStack::create($this->handle(...)),
        ]);
    }

    /**
     * Answer the next request with this, instead of a plain 200.
     */
    public function willRespondWith(Response ...$responses): static
    {
        foreach ($responses as $response) {
            $this->queued[] = $response;
        }

        return $this;
    }

    /**
     * @return array<int, SentRequest>
     */
    public function sent(?string $type = null): array
    {
        if ($type === null) {
            return $this->sent;
        }

        return array_values(array_filter(
            $this->sent,
            fn (SentRequest $request): bool => $request->type() === $type
        ));
    }

    public function first(?string $type = null): SentRequest
    {
        $sent = $this->sent($type);

        Assert::assertNotEmpty(
            $sent,
            $type === null
                ? 'Expected the package to send something, it sent nothing.'
                : "Expected the package to send a [{$type}], it sent: ".$this->summary()
        );

        return $sent[0];
    }

    public function assertSentCount(int $expected, ?string $type = null): static
    {
        Assert::assertCount(
            $expected,
            $this->sent($type),
            'Wrong number of requests sent. Sent: '.$this->summary()
        );

        return $this;
    }

    public function assertNothingSent(): static
    {
        Assert::assertSame([], $this->sent, 'Expected silence, but sent: '.$this->summary());

        return $this;
    }

    /**
     * What was sent, for a failure message worth reading.
     */
    public function summary(): string
    {
        if ($this->sent === []) {
            return '(nothing)';
        }

        return implode(', ', array_map(
            fn (SentRequest $request): string => $request->type().' -> '.$request->url,
            $this->sent
        ));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function handle(RequestInterface $request, array $options): mixed
    {
        $this->sent[] = SentRequest::from($request, $options);

        return Create::promiseFor(
            array_shift($this->queued) ?? new Response(200, [], json_encode(['id' => 'integration-test']))
        );
    }
}
