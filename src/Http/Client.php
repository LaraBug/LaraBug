<?php

namespace LaraBug\Http;

use Exception;
use GuzzleHttp\ClientInterface;
use LaraBug\Requests\Traceparent;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;

class Client
{
    public function __construct(
        protected readonly string $login,
        protected readonly string $project,
        protected ?ClientInterface $client = null,
    ) {
    }

    /**
     * No native signature on purpose: test doubles override this method
     * with a loose one.
     *
     * @param array<string, mixed> $exception
     * @return ResponseInterface|null
     */
    public function report($exception)
    {
        return $this->send(array_merge([
            'project' => $this->project,
            'additional' => [],
        ], $exception), [
            'allow_redirects' => [
                'max' => 5,
                'strict' => true,  // Preserve POST method on redirects
                'referer' => true,
                'protocols' => ['http', 'https'],
                'track_redirects' => false,
            ],
        ]);
    }

    /**
     * Report a batch of served HTTP requests.
     *
     * The same endpoint and envelope as everything else, told apart by type.
     * Each record carries the queries it ran inline, because they are one
     * execution: a request whose queries arrived separately could have half
     * of itself stored.
     *
     * @param  array<int, array<string, mixed>>  $records
     */
    public function reportRequests(array $records): ?ResponseInterface
    {
        return $this->send([
            'type' => 'requests_batch',
            'project' => $this->project,
            'requests' => $records,
            'count' => count($records),
        ], [
            // Shorter than a report's fifteen. This runs on shutdown while
            // the worker is still held, so our slowness is their capacity.
            'timeout' => 5,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     */
    public function reportCommands(array $records): ?ResponseInterface
    {
        return $this->send([
            'type' => 'commands_batch',
            'project' => $this->project,
            'commands' => $records,
            'count' => count($records),
        ], ['timeout' => 5]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     */
    public function reportScheduledTasks(array $records): ?ResponseInterface
    {
        return $this->send([
            'type' => 'scheduled_tasks_batch',
            'project' => $this->project,
            'scheduled_tasks' => $records,
            'count' => count($records),
        ], ['timeout' => 5]);
    }

    /**
     * Report how much was waiting on each queue when the scheduler last looked.
     *
     * @param  array<int, array<string, mixed>>  $samples
     */
    public function reportQueueDepth(array $samples): ?ResponseInterface
    {
        return $this->send([
            'type' => 'queue_depth_batch',
            'project' => $this->project,
            'count' => count($samples),
            'samples' => $samples,
        ], [
            // This runs every minute from the scheduler, and a server that
            // has gone away should not hold the schedule open behind it.
            'timeout' => 5,
        ]);
    }

    /**
     * Send a payload the retry buffer kept, byte for byte as it was kept.
     * Never kept again: the buffer decides what a failure means for it.
     *
     * @internal For RetryBuffer.
     *
     * @throws TransferException When the server could not be reached.
     */
    public function resend(string $json): ResponseInterface
    {
        // Short, since the flush may run while a worker is still held, and
        // the first failure ends it anyway.
        return $this->post(['body' => $json, 'timeout' => 5]);
    }

    /**
     * Whether a payload refused with a 5xx, or one that could not reach the
     * server, is kept to be sent again later. Senders that retry on their own
     * leave it to the buffer when it is, rather than sending it twice.
     */
    public function keepsFailedSends(): bool
    {
        return RetryBuffer::fromConfig() !== null;
    }

    /**
     * Send a payload, and keep it in the retry buffer when the server failed
     * to take it.
     *
     * Returns null when the server could not be reached, or when it asked not
     * to be sent anything yet and the payload went straight to the buffer.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $options
     */
    protected function send(array $payload, array $options = []): ?ResponseInterface
    {
        $retryBuffer = RetryBuffer::fromConfig();

        if ($retryBuffer?->accepts($payload) && $retryBuffer->isPaused()) {
            $retryBuffer->keep($payload);

            return null;
        }

        try {
            $response = $this->post(['json' => $payload, ...$options]);
        } catch (TransferException) {
            $response = null;
        } catch (Exception) {
            // Not the network but the payload or the client itself. Keeping
            // it would only fail the same way when it is sent again.
            return null;
        }

        $retryBuffer?->handle($payload, $response, $this);

        return $response;
    }

    /**
     * POST to the server. Answers with whatever the server answered, error
     * statuses included.
     *
     * @param  array<string, mixed>  $options  Guzzle request options, the body among them.
     *
     * @throws TransferException When there is no answer at all.
     */
    protected function post(array $options): ResponseInterface
    {
        try {
            return $this->getGuzzleHttpClient()->request('POST', config('larabug.server'), [
                'headers' => $this->headers(),
                // Unset means verify, as it did on Guzzle 7. Guzzle 8 rejects a
                // null here, and with it every report.
                'verify' => config('larabug.verify_ssl') ?? true,
                ...$options,
            ]);
        } catch (RequestException $e) {
            // Guzzle 8 keeps getResponse() on ResponseException alone. A
            // RequestException without it never had an answer, as one whose
            // getResponse() is null did not on Guzzle 7.
            $response = method_exists($e, 'getResponse') ? $e->getResponse() : null;

            if ($response === null) {
                throw $e;
            }

            return $response;
        }
    }

    /**
     * The traceparent is what lets the receiving end continue this execution's
     * story rather than start one of its own. Built per call, because its span
     * names this one request.
     *
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->login,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'LaraBug-Package',
            'traceparent' => Traceparent::forCurrentTrace(),
        ];
    }

    public function getGuzzleHttpClient(): ClientInterface
    {
        return $this->client ??= new \GuzzleHttp\Client([
            'timeout' => 15,
        ]);
    }

    public function setGuzzleHttpClient(ClientInterface $client): static
    {
        $this->client = $client;

        return $this;
    }
}
