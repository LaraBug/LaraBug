<?php

namespace LaraBug\Tests\Mocks;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Assert;

/**
 * Records what was sent and answers with whatever a test has queued up, so a
 * metered server can be played: a 402 naming a stream, then a plain 200 for
 * whatever is sent next.
 *
 * Anything sent past the end of the queue gets an ordinary 200, which keeps a
 * test that only cares about one refusal from having to spell out the rest.
 */
class MeteredClient extends \LaraBug\Http\Client
{
    /** @var array<int, array<string, mixed>> */
    public array $requests = [];

    /** @var array<int, Response> */
    protected array $answers = [];

    public function __construct()
    {
        parent::__construct('login-key', 'project-key');
    }

    public function willAnswer(Response $response): static
    {
        $this->answers[] = $response;

        return $this;
    }

    /**
     * Queue a "you are over your allowance" answer.
     *
     * @param  string|null  $stream  The stream the server names, or null to leave
     *                               the body silent about which one it was.
     * @param  string|null  $retryAfter  The Retry-After header, verbatim.
     */
    public function willRefuse(?string $stream = null, ?string $retryAfter = null): static
    {
        return $this->willAnswer(new Response(
            402,
            $retryAfter === null ? [] : ['Retry-After' => $retryAfter],
            json_encode($stream === null ? ['message' => 'Allowance spent'] : ['stream' => $stream])
        ));
    }

    public function willRespondWith(int $status): static
    {
        return $this->willAnswer(new Response($status, [], '{}'));
    }

    public function report($exception): Response
    {
        return $this->answer($exception);
    }

    public function reportRequests(array $records): Response
    {
        return $this->answer(['type' => 'requests_batch', 'requests' => $records]);
    }

    public function reportCommands(array $records): Response
    {
        return $this->answer(['type' => 'commands_batch', 'commands' => $records]);
    }

    public function reportScheduledTasks(array $records): Response
    {
        return $this->answer(['type' => 'scheduled_tasks_batch', 'scheduled_tasks' => $records]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function lastRequest(): ?array
    {
        return $this->requests ? end($this->requests) : null;
    }

    public function assertRequestsSent(int $expectedCount): void
    {
        Assert::assertCount($expectedCount, $this->requests);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function answer(array $payload): Response
    {
        $this->requests[] = $payload;

        return array_shift($this->answers) ?? new Response(200, [], json_encode(['id' => 'test']));
    }
}
