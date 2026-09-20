<?php

namespace LaraBug\Tests\Integration;

use RuntimeException;
use GuzzleHttp\Psr7\Response;
use ExampleApp\Jobs\RecordedJob;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Contracts\Debug\ExceptionHandler;

/**
 * What a 402 stops.
 *
 * The backoff is process wide, so a long lived worker that collects one on a
 * Monday must not be silent until it restarts, and a refusal on one stream
 * must not take the other down with it. Neither of those is visible until a
 * whole application is sending more than one kind of thing.
 */
class SendingLimitsTest extends IntegrationTestCase
{
    #[Test]
    public function an_issue_limit_stops_reports_and_leaves_telemetry_alone(): void
    {
        $this->transport->willRespondWith($this->refusal('issues'));

        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('the first one'));
        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('the second one'));

        $this->transport->assertSentCount(1, 'report');

        RecordedJob::dispatch();

        $this->transport->assertSentCount(1, 'queue_job');
    }

    #[Test]
    public function a_telemetry_limit_stops_telemetry_and_leaves_reports_alone(): void
    {
        $this->transport->willRespondWith($this->refusal('telemetry'));

        RecordedJob::dispatch();
        RecordedJob::dispatch();

        $this->transport->assertSentCount(1, 'queue_job');

        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('still reported'));

        $this->transport->assertSentCount(1, 'report');
    }

    #[Test]
    public function a_refusal_that_names_no_stream_only_holds_back_the_one_that_collected_it(): void
    {
        $this->transport->willRespondWith(new Response(402, ['Retry-After' => '60'], '{}'));

        RecordedJob::dispatch();
        RecordedJob::dispatch();

        $this->transport->assertSentCount(1, 'queue_job');

        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('unaffected'));

        $this->transport->assertSentCount(1, 'report');
    }

    #[Test]
    public function a_server_error_is_not_a_limit(): void
    {
        $this->transport->willRespondWith(new Response(500, [], '{}'));

        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('the first one'));
        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('the second one'));

        $this->transport->assertSentCount(2, 'report');
    }

    protected function refusal(string $stream): Response
    {
        return new Response(402, ['Retry-After' => '300'], json_encode(['stream' => $stream]));
    }
}
