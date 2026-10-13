<?php

namespace LaraBug\Tests;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LaraBug\Queue\JobMonitor;
use LaraBug\Requests\Sampler;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Queue\SyncQueue;
use LaraBug\Mail\MailListeners;
use LaraBug\Console\CommandBuffer;
use LaraBug\Requests\TraceContext;
use Illuminate\Container\Container;
use Illuminate\Queue\WorkerOptions;
use Workbench\App\FailingTransport;
use Workbench\App\Jobs\SendInvoice;
use Workbench\App\Mail\InvoiceMail;
use Illuminate\Support\Facades\Mail;
use LaraBug\Requests\RequestMonitor;
use LaraBug\Console\CommandListeners;
use LaraBug\Queue\JobEventSubscriber;
use LaraBug\Requests\RequestListeners;
use LaraBug\Tests\Mocks\LaraBugClient;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Workbench\App\Notifications\OrderShipped;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Contracts\Queue\Job as JobContract;
use Workbench\App\Notifications\QueuedOrderShipped;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;

/**
 * Mail, end to end: the real mailer, the real worker and the real artisan,
 * with only the records LaraBug would send captured on the way out.
 */
class MailMonitoringTest extends TestCase
{
    protected MailListeners $mail;

    protected JobMonitor $jobs;

    protected CommandBuffer $commands;

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('mail.default', 'array');
        $app['config']->set('mail.mailers.failing', ['transport' => 'failing']);

        // The provider's own listeners would send to a LaraBug that is not
        // there; each test wires its own, recording ones instead.
        $app['config']->set('larabug.jobs.track_jobs', false);
        $app['config']->set('larabug.commands.track_commands', false);
        $app['config']->set('larabug.schedule.track_scheduled_tasks', false);
    }

    public function setUp(): void
    {
        parent::setUp();

        Mail::extend('failing', fn () => new FailingTransport());

        // Laravel only turns Symfony's console events into its own outside of
        // unit tests, and the command listener hears nothing else.
        $this->app->make(ConsoleKernel::class)->rerouteSymfonyCommandEvents();

        $this->listen();
    }

    protected function tearDown(): void
    {
        TraceContext::reset();

        parent::tearDown();
    }

    #[Test]
    public function a_job_that_sends_a_mailable_inline_carries_it_on_its_completed_record()
    {
        $this->work(new SendInvoice());

        $record = $this->jobRecord();

        $this->assertSame('completed', $record['status']);
        $this->assertSame(1, $record['mail_sent']);
        $this->assertSame(1.0, $record['sample_rate']);
        $this->assertCount(1, $record['mail']);

        $message = $record['mail'][0];
        $this->assertSame(InvoiceMail::class, $message['mailable']);
        $this->assertSame('Your invoice', $message['subject']);
        $this->assertSame(1, $message['to_count']);
        $this->assertSame('example.com', $message['recipient_domains']);
        $this->assertSame(0, $message['queued']);
        $this->assertSame(0, $message['failed']);
        $this->assertArrayHasKey('duration_ms', $message);
        $this->assertGreaterThanOrEqual(0.0, $message['start_ms']);
    }

    #[Test]
    public function a_completed_record_carries_the_rate_it_was_sampled_at()
    {
        $this->listen(['sample_rate' => 2.0]);

        $this->work(new SendInvoice());

        $this->assertSame(1.0, $this->jobRecord()['sample_rate']);
    }

    #[Test]
    public function a_queued_mailable_is_recorded_on_the_job_that_delivers_it_as_queued()
    {
        $this->work(new SendQueuedMailable((new InvoiceMail())->to('alice@example.com')));

        $record = $this->jobRecord();

        $this->assertSame(1, $record['mail_sent']);
        $this->assertSame(InvoiceMail::class, $record['mail'][0]['mailable']);
        $this->assertSame(1, $record['mail'][0]['queued']);
        $this->assertSame('Your invoice', $record['mail'][0]['subject']);
    }

    #[Test]
    public function a_queued_notification_is_recorded_on_the_job_that_delivers_it_as_queued()
    {
        $notifiable = (new AnonymousNotifiable())->route('mail', 'bob@example.org');

        $this->work(new SendQueuedNotifications($notifiable, new QueuedOrderShipped(), ['mail']));

        $message = $this->jobRecord()['mail'][0];

        $this->assertSame(QueuedOrderShipped::class, $message['mailable']);
        $this->assertSame('Your order shipped', $message['subject']);
        $this->assertSame('example.org', $message['recipient_domains']);
        $this->assertSame(1, $message['queued']);
    }

    #[Test]
    public function the_mail_of_one_job_never_appears_on_the_next()
    {
        $this->work(new SendInvoice());
        $this->work(new SendInvoice(sends: false));

        [$first, $second] = $this->jobs->records;

        $this->assertSame(1, $first['mail_sent']);
        $this->assertSame(0, $second['mail_sent']);
        $this->assertSame([], $second['mail']);
    }

    #[Test]
    public function an_attempt_that_was_released_is_closed_once_the_next_job_starts()
    {
        $work = fn (object $job, int $maxTries) => $this->work($job, $maxTries);

        Artisan::command('app:work-invoices', function () use ($work) {
            // Unlimited tries, so the throw releases the job rather than
            // failing it: no processed event, no failed event, nothing to
            // close it on but the next job.
            $work(new SendInvoice(throwsAfterwards: true), 0);
            $work(new SendInvoice(sends: false), 1);

            Mail::to('ops@example.com')->send(new InvoiceMail());
        });

        Artisan::call('app:work-invoices');

        $this->assertCount(1, $this->jobs->records);
        $this->assertSame(0, $this->jobs->records[0]['mail_sent']);

        // The released attempt no longer holds the floor: the worker's own
        // message lands on the worker.
        $this->assertSame(1, $this->commandRecord()['mail_sent']);
    }

    #[Test]
    public function a_send_that_fails_inside_a_job_is_recorded_as_failed_on_its_failed_record()
    {
        $this->work(new SendInvoice(mailer: 'failing'));

        $record = $this->jobRecord();

        $this->assertSame('failed', $record['status']);
        $this->assertSame(1.0, $record['sample_rate']);
        $this->assertSame(1, $record['mail_sent']);

        $message = $record['mail'][0];
        $this->assertSame(1, $message['failed']);
        $this->assertSame(InvoiceMail::class, $message['mailable']);
        $this->assertSame('Your invoice', $message['subject']);
        $this->assertSame(0, $message['to_count']);
    }

    #[Test]
    public function a_job_that_sent_and_then_failed_keeps_the_mail_that_went_out()
    {
        $this->work(new SendInvoice(throwsAfterwards: true));

        $record = $this->jobRecord();

        $this->assertSame('failed', $record['status']);
        $this->assertSame(1, $record['mail_sent']);
        $this->assertSame(0, $record['mail'][0]['failed']);
    }

    #[Test]
    public function an_ignored_job_records_nothing_and_hands_its_mail_to_nobody()
    {
        $this->listen(['ignore_jobs' => [SendInvoice::class]]);

        $work = fn (object $job) => $this->work($job);

        Artisan::command('app:work-invoices', fn () => $work(new SendInvoice()));

        Artisan::call('app:work-invoices');

        $this->assertSame([], $this->jobs->records);
        $this->assertSame(0, $this->commandRecord()['mail_sent']);
    }

    #[Test]
    public function a_command_that_sends_mail_carries_it()
    {
        Artisan::command('app:send-digest', function () {
            Mail::to('alice@example.com')->send(new InvoiceMail());
        });

        Artisan::call('app:send-digest');

        $record = $this->commandRecord();

        $this->assertSame('app:send-digest', $record['command']);
        $this->assertSame(1, $record['mail_sent']);
        $this->assertSame(InvoiceMail::class, $record['mail'][0]['mailable']);
        $this->assertSame(0, $record['mail'][0]['queued']);
    }

    #[Test]
    public function a_send_that_fails_inside_a_command_is_recorded_as_failed_on_it()
    {
        Artisan::command('app:send-digest', function () {
            try {
                Mail::mailer('failing')->to('alice@example.com')->send(new InvoiceMail());
            } catch (\Throwable) {
            }
        });

        Artisan::call('app:send-digest');

        $record = $this->commandRecord();

        $this->assertSame(1, $record['mail_sent']);
        $this->assertSame(1, $record['mail'][0]['failed']);
    }

    #[Test]
    public function a_job_run_by_a_worker_command_carries_its_mail_and_the_command_does_not()
    {
        $work = fn (object $job) => $this->work($job);

        Artisan::command('app:work-invoices', function () use ($work) {
            Mail::to('ops@example.com')->send(new InvoiceMail());

            $work(new SendInvoice());
        });

        Artisan::call('app:work-invoices');

        $this->assertSame(1, $this->jobRecord()['mail_sent']);
        $this->assertSame('example.com', $this->jobRecord()['mail'][0]['recipient_domains']);

        // Only the message the command sent itself, before the job ran.
        $command = $this->commandRecord();
        $this->assertSame(1, $command['mail_sent']);
        $this->assertCount(1, $command['mail']);
    }

    #[Test]
    public function a_sync_job_inside_a_request_owns_its_mail_and_the_request_does_not_record_it()
    {
        $request = new RequestMonitor();

        $this->listen(request: $request);

        $this->app['queue']->connection('sync')->push(new SendInvoice());

        $record = $request->toArray(Request::create('/checkout', 'POST'), new Response('', 200), 1.0);

        $this->assertSame(0, $record['mail_sent']);
        $this->assertSame([], $record['mail']);
        $this->assertSame(1, $this->jobRecord()['mail_sent']);
    }

    #[Test]
    public function mail_sent_by_a_request_outside_any_job_stays_on_the_request()
    {
        $request = new RequestMonitor();

        $this->listen(request: $request);

        Mail::to('alice@example.com')->send(new InvoiceMail());

        $record = $request->toArray(Request::create('/checkout', 'POST'), new Response('', 200), 1.0);

        $this->assertSame(1, $record['mail_sent']);
        $this->assertSame(InvoiceMail::class, $record['mail'][0]['mailable']);
    }

    #[Test]
    public function a_notification_over_mail_is_recorded_as_mail_and_still_as_a_notification()
    {
        $request = new RequestMonitor();

        $this->listen(request: $request);

        (new RequestListeners($request, new Sampler()))->subscribe($this->app['events']);

        Notification::route('mail', 'bob@example.org')->notify(new OrderShipped());

        $record = $request->toArray(Request::create('/orders/1/ship', 'POST'), new Response('', 200), 1.0);

        $this->assertSame(1, $record['mail_sent']);
        $this->assertSame(OrderShipped::class, $record['mail'][0]['mailable']);
        $this->assertSame('Your order shipped', $record['mail'][0]['subject']);
        $this->assertSame(0, $record['mail'][0]['queued']);

        $this->assertSame(1, $record['notifications_sent']);
        $this->assertSame(OrderShipped::class, $record['notifications'][0]['notification']);
        $this->assertSame('mail', $record['notifications'][0]['channel']);
    }

    /**
     * Fresh, recording listeners on the application's own dispatcher. Called
     * again by a test that needs different settings: the dispatcher is
     * flushed first, so the previous set stops listening.
     *
     * @param  array<string, mixed>  $jobs
     */
    private function listen(array $jobs = [], ?RequestMonitor $request = null): void
    {
        $events = $this->app['events'];

        foreach (['Illuminate\Mail\Events\MessageSending', 'Illuminate\Mail\Events\MessageSent', 'Illuminate\Queue\Events\JobProcessing', 'Illuminate\Queue\Events\JobProcessed', 'Illuminate\Queue\Events\JobFailed', 'Illuminate\Console\Events\CommandStarting', 'Illuminate\Console\Events\CommandFinished'] as $event) {
            $events->forget($event);
        }

        $this->mail = new MailListeners($request);
        $this->mail->subscribe($events);

        $this->jobs = new class (new LaraBugClient('login', 'project'), ['jobs' => $jobs + ['track_jobs' => true]]) extends JobMonitor {
            /** @var array<int, array<string, mixed>> */
            public array $records = [];

            protected function send(array $data): void
            {
                $this->records[] = $data;
            }
        };

        (new JobEventSubscriber($this->jobs, $this->mail))->subscribe($events);

        // Skips the parent constructor, so no shutdown flush is registered and no client is needed.
        $this->commands = new class () extends CommandBuffer {
            /** @var array<int, array<string, mixed>> */
            public array $records = [];

            public function __construct()
            {
            }

            public function add(array $record): void
            {
                $this->records[] = $record;
            }
        };

        (new CommandListeners($this->commands, $this->mail))->subscribe($events);
    }

    /**
     * Process one job the way `queue:work` does, off a queue that is not the
     * sync one, swallowing the exception the worker would have reported.
     */
    private function work(object $job, int $maxTries = 1): void
    {
        $queueJob = new FakeQueueJob($this->app, $this->payloadFor($job));

        try {
            $this->app->make('queue.worker')->process('redis', $queueJob, new WorkerOptions(maxTries: $maxTries));
        } catch (\Throwable) {
        }
    }

    private function payloadFor(object $job): string
    {
        $queue = new class () extends SyncQueue {
            public function payloadFor(object $job): string
            {
                return $this->createPayload($job, 'default');
            }
        };

        $queue->setContainer($this->app);

        return $queue->payloadFor($job);
    }

    /** @return array<string, mixed> */
    private function jobRecord(): array
    {
        $this->assertCount(1, $this->jobs->records);

        return $this->jobs->records[0];
    }

    /** @return array<string, mixed> */
    private function commandRecord(): array
    {
        $this->assertCount(1, $this->commands->records);

        return $this->commands->records[0];
    }
}

/**
 * A job off a queue that is not sync, holding its payload in memory: enough
 * for the worker to fire, release, fail and delete it without a backend.
 */
class FakeQueueJob extends Job implements JobContract
{
    public function __construct(Container $container, protected string $rawBody)
    {
        $this->container = $container;
        $this->connectionName = 'redis';
        $this->queue = 'default';
    }

    public function getJobId()
    {
        return $this->payload()['uuid'] ?? '';
    }

    public function getRawBody()
    {
        return $this->rawBody;
    }

    public function attempts()
    {
        return 1;
    }
}
