<?php

namespace LaraBug\Tests;

use Exception;
use LaraBug\LaraBug;
use ReflectionClass;
use LaraBug\Logger\LogBuffer;
use LaraBug\Queue\EventBuffer;
use LaraBug\Console\CommandBuffer;
use Illuminate\Support\Facades\Log;
use LaraBug\Requests\RequestBuffer;
use LaraBug\Support\AllowanceBackoff;
use LaraBug\Http\Client as HttpClient;
use LaraBug\Tests\Mocks\MeteredClient;
use PHPUnit\Framework\Attributes\Test;
use LaraBug\Console\ScheduledTaskBuffer;

class AllowanceBackoffTest extends TestCase
{
    protected MeteredClient $client;

    public function setUp(): void
    {
        parent::setUp();

        $this->client = new MeteredClient();
        $this->app->instance(HttpClient::class, $this->client);

        $this->app['config']['larabug.project_key'] = 'project';
        $this->app['config']['larabug.environments'] = ['testing'];
        $this->app['config']['logging.default'] = 'larabug-logs';
    }

    #[Test]
    public function a_spent_telemetry_allowance_stops_the_batch_after_it()
    {
        $this->client->willRefuse(AllowanceBackoff::TELEMETRY);

        Log::info('Refused');
        $this->logs()->flush();

        $this->client->assertRequestsSent(1);

        Log::info('Not even attempted');
        $this->logs()->flush();

        $this->client->assertRequestsSent(1);
    }

    #[Test]
    public function the_stream_sends_again_once_the_window_has_passed()
    {
        $this->client->willRefuse(AllowanceBackoff::TELEMETRY);

        Log::info('Refused');
        $this->logs()->flush();

        $this->client->assertRequestsSent(1);

        $this->windowHasPassed(AllowanceBackoff::TELEMETRY);

        Log::info('Sent on the other side of the window');
        $this->logs()->flush();

        $this->client->assertRequestsSent(2);
        $this->assertNull(AllowanceBackoff::resumesAt(AllowanceBackoff::TELEMETRY));
    }

    #[Test]
    public function it_waits_as_long_as_the_server_asked_for()
    {
        $this->client->willRefuse(AllowanceBackoff::TELEMETRY, '90');

        Log::info('Refused');
        $this->logs()->flush();

        $this->assertEqualsWithDelta(
            time() + 90,
            AllowanceBackoff::resumesAt(AllowanceBackoff::TELEMETRY),
            1
        );
    }

    #[Test]
    public function it_waits_five_minutes_when_the_server_does_not_say()
    {
        $this->client->willRefuse(AllowanceBackoff::TELEMETRY);

        Log::info('Refused');
        $this->logs()->flush();

        $this->assertEqualsWithDelta(
            time() + 300,
            AllowanceBackoff::resumesAt(AllowanceBackoff::TELEMETRY),
            1
        );
    }

    #[Test]
    public function it_waits_five_minutes_when_retry_after_makes_no_sense()
    {
        $this->client->willRefuse(AllowanceBackoff::TELEMETRY, 'whenever suits you');

        Log::info('Refused');
        $this->logs()->flush();

        $this->assertEqualsWithDelta(
            time() + 300,
            AllowanceBackoff::resumesAt(AllowanceBackoff::TELEMETRY),
            1
        );
    }

    #[Test]
    public function it_waits_until_the_date_retry_after_names()
    {
        $this->client->willRefuse(AllowanceBackoff::TELEMETRY, $this->httpDate(time() + 120));

        Log::info('Refused');
        $this->logs()->flush();

        $this->assertEqualsWithDelta(
            time() + 120,
            AllowanceBackoff::resumesAt(AllowanceBackoff::TELEMETRY),
            1
        );
    }

    #[Test]
    public function a_date_retry_after_that_has_already_passed_holds_nothing()
    {
        $this->client->willRefuse(AllowanceBackoff::TELEMETRY, $this->httpDate(time() - 120));

        Log::info('Refused');
        $this->logs()->flush();

        $this->assertNull(AllowanceBackoff::resumesAt(AllowanceBackoff::TELEMETRY));

        Log::info('Sent right after');
        $this->logs()->flush();

        $this->client->assertRequestsSent(2);
    }

    #[Test]
    public function a_retry_after_of_zero_asks_for_no_window_at_all()
    {
        $this->client->willRefuse(AllowanceBackoff::TELEMETRY, '0');

        Log::info('Refused');
        $this->logs()->flush();

        $this->assertNull(AllowanceBackoff::resumesAt(AllowanceBackoff::TELEMETRY));

        Log::info('Sent right after');
        $this->logs()->flush();

        $this->client->assertRequestsSent(2);
    }

    #[Test]
    public function it_never_holds_a_stream_for_longer_than_an_hour()
    {
        $this->client->willRefuse(AllowanceBackoff::TELEMETRY, '99999999');

        Log::info('Refused');
        $this->logs()->flush();

        $this->assertEqualsWithDelta(
            time() + AllowanceBackoff::MAX_COOLDOWN,
            AllowanceBackoff::resumesAt(AllowanceBackoff::TELEMETRY),
            1
        );
    }

    #[Test]
    public function the_refused_response_is_still_readable_by_the_sender_that_got_it()
    {
        $this->client->willRefuse();

        $response = (new LaraBug($this->client))->handle(new Exception('Refused'));

        $this->assertSame('Allowance spent', $response->message);
    }

    #[Test]
    public function a_telemetry_refusal_leaves_issues_alone()
    {
        $this->client->willRefuse(AllowanceBackoff::TELEMETRY);

        Log::info('Refused');
        $this->logs()->flush();

        (new LaraBug($this->client))->handle(new Exception('The one that took the site down'));

        $this->client->assertRequestsSent(2);
        $this->assertArrayHasKey('exception', $this->client->lastRequest());
        $this->assertNull(AllowanceBackoff::resumesAt(AllowanceBackoff::ISSUES));
    }

    #[Test]
    public function an_issue_refusal_leaves_telemetry_alone()
    {
        $this->client->willRefuse(AllowanceBackoff::ISSUES);

        $larabug = new LaraBug($this->client);
        $larabug->handle(new Exception('Refused'));
        $larabug->handle(new Exception('Not even attempted'));

        $this->client->assertRequestsSent(1);

        Log::info('Still shipping');
        $this->logs()->flush();

        $this->client->assertRequestsSent(2);
        $this->assertSame('logs_batch', $this->client->lastRequest()['type']);
    }

    #[Test]
    public function a_refusal_without_a_stream_holds_only_the_one_being_sent()
    {
        $this->client->willRefuse();

        (new LaraBug($this->client))->handle(new Exception('Refused'));

        $this->assertNotNull(AllowanceBackoff::resumesAt(AllowanceBackoff::ISSUES));
        $this->assertNull(AllowanceBackoff::resumesAt(AllowanceBackoff::TELEMETRY));
    }

    #[Test]
    public function one_refused_telemetry_sender_quietens_the_others()
    {
        $this->client->willRefuse(AllowanceBackoff::TELEMETRY);

        $requests = new RequestBuffer($this->client, $this->app['config']->get('larabug', []));
        $requests->add(['uri' => '/checkout']);
        $requests->flush();

        $this->client->assertRequestsSent(1);

        Log::info('Same allowance, different sender');
        $this->logs()->flush();

        $this->client->assertRequestsSent(1);
    }

    #[Test]
    public function a_403_still_stops_logging_for_the_life_of_the_process()
    {
        $this->client->willRespondWith(403);

        Log::info('Refused');
        $this->logs()->flush();

        $this->client->assertRequestsSent(1);

        // Nothing to wait out: a switched-off feature is not a window, so
        // clearing every backoff leaves the channel just as shut.
        AllowanceBackoff::clear();

        $this->assertFalse($this->logs()->enabled());

        Log::info('Not even attempted');
        $this->logs()->flush();

        $this->client->assertRequestsSent(1);
    }

    #[Test]
    public function a_422_still_stops_logging_for_the_life_of_the_process()
    {
        $this->client->willRespondWith(422);

        Log::info('Rejected');
        $this->logs()->flush();

        AllowanceBackoff::clear();

        $this->assertFalse($this->logs()->enabled());

        Log::info('Not even attempted');
        $this->logs()->flush();

        $this->client->assertRequestsSent(1);
    }

    #[Test]
    public function a_spent_telemetry_allowance_stops_the_command_buffer()
    {
        $this->client->willRefuse(AllowanceBackoff::TELEMETRY);

        $commands = new CommandBuffer($this->client, $this->config());
        $commands->add(['command' => 'migrate']);
        $commands->flush();

        $this->client->assertRequestsSent(1);

        $commands->add(['command' => 'queue:work']);
        $commands->flush();

        $this->client->assertRequestsSent(1);
    }

    #[Test]
    public function a_spent_telemetry_allowance_stops_the_scheduled_task_buffer()
    {
        $this->client->willRefuse(AllowanceBackoff::TELEMETRY);

        $tasks = new ScheduledTaskBuffer($this->client, $this->config());
        $tasks->add(['task' => 'backup:run']);
        $tasks->flush();

        $this->client->assertRequestsSent(1);

        $tasks->add(['task' => 'sitemap:generate']);
        $tasks->flush();

        $this->client->assertRequestsSent(1);
    }

    #[Test]
    public function a_spent_telemetry_allowance_stops_the_queue_job_buffer()
    {
        $this->client->willRefuse(AllowanceBackoff::TELEMETRY);

        $jobs = new EventBuffer($this->client, $this->config());
        $jobs->add(['job' => 'SendInvoice']);

        $this->client->assertRequestsSent(1);

        $jobs->add(['job' => 'SendReminder']);

        $this->client->assertRequestsSent(1);
    }

    #[Test]
    public function jobs_that_arrive_during_a_hold_still_count_toward_the_batching_decision()
    {
        $this->app['config']['larabug.jobs.auto_batch_threshold'] = 3;

        $this->client->willRefuse(AllowanceBackoff::TELEMETRY);

        $jobs = new EventBuffer($this->client, $this->config());
        $jobs->add(['job' => 'SendInvoice']);
        $jobs->add(['job' => 'SendReminder']);
        $jobs->add(['job' => 'SendReceipt']);

        $this->client->assertRequestsSent(1);

        $this->windowHasPassed(AllowanceBackoff::TELEMETRY);

        $jobs->add(['job' => 'SendStatement']);

        // Batching is on because the three held-back jobs were still counted,
        // so this one waits for a batch instead of paying for its own request.
        $this->client->assertRequestsSent(1);
        $this->assertSame(1, $jobs->count());
    }

    /**
     * @return array<string, mixed>
     */
    protected function config(): array
    {
        return $this->app['config']->get('larabug', []);
    }

    protected function httpDate(int $timestamp): string
    {
        return gmdate('D, d M Y H:i:s \G\M\T', $timestamp);
    }

    protected function logs(): LogBuffer
    {
        return $this->app[LogBuffer::class];
    }

    /**
     * Move a stream's window into the past rather than sleeping through it.
     */
    protected function windowHasPassed(string $stream): void
    {
        $property = (new ReflectionClass(AllowanceBackoff::class))->getProperty('resumeAt');

        $resumeAt = $property->getValue();
        $resumeAt[$stream] = time() - 1;

        $property->setValue(null, $resumeAt);
    }
}
