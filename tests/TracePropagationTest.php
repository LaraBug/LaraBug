<?php

namespace LaraBug\Tests;

use Exception;
use LaraBug\LaraBug;
use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;
use Illuminate\Http\Request;
use GuzzleHttp\Psr7\Response;
use LaraBug\Logger\LogBuffer;
use LaraBug\Queue\JobMonitor;
use LaraBug\Requests\Sampler;
use Illuminate\Queue\SyncQueue;
use GuzzleHttp\Client as Guzzle;
use LaraBug\Requests\Traceparent;
use Illuminate\Queue\Jobs\SyncJob;
use LaraBug\Console\CommandBuffer;
use LaraBug\Requests\TraceContext;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Log;
use LaraBug\Queue\JobDataCollector;
use LaraBug\Requests\RequestBuffer;
use LaraBug\Requests\RequestMonitor;
use LaraBug\Console\CommandListeners;
use LaraBug\Queue\JobEventSubscriber;
use LaraBug\Http\Client as HttpClient;
use LaraBug\Tests\Mocks\LaraBugClient;
use PHPUnit\Framework\Attributes\Test;
use LaraBug\Console\ScheduledTaskBuffer;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use LaraBug\Console\ScheduledTaskListeners;
use LaraBug\Http\Middleware\CaptureRequest;
use Illuminate\Http\Response as HttpResponse;

class TracePropagationTest extends TestCase
{
    protected function tearDown(): void
    {
        // The trace is process wide, so a test that leaves a parent behind
        // would hand it to the next one.
        TraceContext::reset();

        parent::tearDown();
    }

    #[Test]
    public function a_child_execution_keeps_a_trace_of_its_own_and_names_the_one_it_came_from()
    {
        $parent = '019998c7-4a2b-4c3d-9e5f-0a1b2c3d4e5f';

        TraceContext::startChild($parent);

        $this->assertSame($parent, TraceContext::parentId());
        $this->assertNotSame($parent, TraceContext::id());
    }

    #[Test]
    public function a_fresh_trace_has_no_parent()
    {
        TraceContext::startChild('019998c7-4a2b-4c3d-9e5f-0a1b2c3d4e5f');

        TraceContext::reset();

        $this->assertNull(TraceContext::parentId());
    }

    #[Test]
    public function a_child_of_nothing_is_not_an_error()
    {
        TraceContext::startChild(null);
        $this->assertNull(TraceContext::parentId());

        TraceContext::startChild('');
        $this->assertNull(TraceContext::parentId());
    }

    #[Test]
    public function a_dispatched_job_carries_the_trace_that_dispatched_it()
    {
        TraceContext::reset();
        $dispatcher = TraceContext::id();

        $payload = $this->payloadFor(new TraceablePropagationJob());

        $this->assertSame($dispatcher, $payload['larabug_parent_trace_id']);
    }

    #[Test]
    public function a_job_processed_in_another_process_becomes_a_child_of_the_trace_that_dispatched_it()
    {
        TraceContext::reset();
        $dispatcher = TraceContext::id();

        $rawBody = json_encode($this->payloadFor(new TraceablePropagationJob()));

        // Minutes later, in a worker that never saw the dispatching request:
        // the payload is all that is left of it.
        TraceContext::reset();

        $this->processJob($rawBody);

        $this->assertSame($dispatcher, TraceContext::parentId());
        $this->assertNotSame($dispatcher, TraceContext::id());
    }

    #[Test]
    public function a_job_pushed_without_a_parent_key_simply_has_none()
    {
        TraceContext::reset();

        $this->processJob(json_encode(['uuid' => 'job-uuid', 'job' => 'App\\Jobs\\Legacy', 'data' => []]));

        $this->assertNull(TraceContext::parentId());
        $this->assertNotSame('', TraceContext::id());
    }

    #[Test]
    public function a_job_whose_payload_is_not_json_does_not_break_the_worker()
    {
        TraceContext::reset();

        $this->processJob('not json at all');

        $this->assertNull(TraceContext::parentId());
    }

    #[Test]
    public function a_job_record_carries_its_own_trace_and_the_one_it_came_from()
    {
        $rawBody = json_encode($this->payloadFor(new TraceablePropagationJob()));

        TraceContext::reset();
        $this->processJob($rawBody);

        $record = (new JobDataCollector(config('larabug')))->collect(
            $this->job($rawBody),
            'redis',
            'completed'
        );

        $this->assertSame(TraceContext::id(), $record['trace_id']);
        $this->assertSame(TraceContext::parentId(), $record['parent_trace_id']);
        $this->assertNotSame($record['trace_id'], $record['parent_trace_id']);
    }

    #[Test]
    public function an_incoming_traceparent_makes_the_request_a_child_of_the_caller()
    {
        $callersTrace = '019998c7-4a2b-4c3d-9e5f-0a1b2c3d4e5f';

        $record = $this->recordForRequestWith(
            '00-'.str_replace('-', '', $callersTrace).'-00f067aa0ba902b7-01'
        );

        $this->assertSame($callersTrace, $record['parent_trace_id']);
        $this->assertNotSame($callersTrace, $record['trace_id']);
    }

    #[Test]
    public function a_request_nobody_traced_has_no_parent()
    {
        $record = $this->recordForRequestWith(null);

        $this->assertSame('', $record['parent_trace_id']);
    }

    #[Test]
    public function a_malformed_traceparent_is_ignored_rather_than_trusted()
    {
        $malformed = [
            'nonsense',
            '',
            // A version we cannot claim to understand.
            '01-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
            // Upper case hex, which the spec does not allow.
            '00-4BF92F3577B34DA6A3CE929D0E0E4736-00f067aa0ba902b7-01',
            // A trace id that is too short, and one that is too long.
            '00-4bf92f3577b34da6a3ce929d0e0e473-00f067aa0ba902b7-01',
            '00-4bf92f3577b34da6a3ce929d0e0e47366-00f067aa0ba902b7-01',
            // Both all-zero forms the spec calls invalid.
            '00-00000000000000000000000000000000-00f067aa0ba902b7-01',
            '00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01',
            // Missing and surplus fields.
            '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7',
            '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01-extra',
            // Something an injection attempt would look like.
            "00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'; drop table",
        ];

        foreach ($malformed as $header) {
            $this->assertNull(Traceparent::parse($header), "Accepted a malformed traceparent: {$header}");

            $record = $this->recordForRequestWith($header);

            $this->assertSame('', $record['parent_trace_id'], "Trusted a malformed traceparent: {$header}");
            $this->assertNotSame('', $record['trace_id']);
        }
    }

    #[Test]
    public function an_outgoing_report_carries_a_traceparent_for_this_execution()
    {
        TraceContext::reset();

        $sent = [];

        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{}')]));
        $stack->push(Middleware::history($sent));

        $client = new HttpClient('login', 'project');
        $client->setGuzzleHttpClient(new Guzzle(['handler' => $stack]));

        $client->report(['exception' => 'boom']);

        $traceparent = $sent[0]['request']->getHeaderLine('traceparent');

        $this->assertSame(
            '00-'.str_replace('-', '', TraceContext::id()),
            substr($traceparent, 0, 35)
        );
        $this->assertMatchesRegularExpression('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/', $traceparent);
    }

    #[Test]
    public function a_traceparent_we_sent_is_one_the_other_side_reads_back_as_our_trace()
    {
        TraceContext::reset();

        $this->assertSame(TraceContext::id(), Traceparent::parse(Traceparent::forCurrentTrace()));
    }

    #[Test]
    public function the_span_names_the_hop_and_not_the_trace()
    {
        TraceContext::reset();

        $this->assertNotSame(Traceparent::forCurrentTrace(), Traceparent::forCurrentTrace());
    }

    #[Test]
    public function an_exception_carries_the_trace_that_caused_the_execution_it_was_thrown_in()
    {
        $parent = '019998c7-4a2b-4c3d-9e5f-0a1b2c3d4e5f';

        TraceContext::startChild($parent);

        $data = (new LaraBug(new LaraBugClient('login', 'project')))
            ->getExceptionData(new Exception('boom'));

        $this->assertSame($parent, $data['parent_trace_id']);
        $this->assertSame(TraceContext::id(), $data['trace_id']);
    }

    #[Test]
    public function a_log_line_carries_the_parent_of_the_execution_that_wrote_it()
    {
        $this->app['config']['logging.default'] = 'larabug-logs';

        $client = new LaraBugClient('login', 'project');
        $this->app->instance(HttpClient::class, $client);

        $parent = '019998c7-4a2b-4c3d-9e5f-0a1b2c3d4e5f';
        TraceContext::startChild($parent);

        Log::info('A line written by a child execution');

        $this->app[LogBuffer::class]->flush();

        $log = $client->requests()[0]['logs'][0];

        $this->assertSame($parent, $log['parent_trace_id']);
        $this->assertSame(TraceContext::id(), $log['trace_id']);
    }

    #[Test]
    public function a_command_record_carries_an_empty_parent_rather_than_leaving_the_field_out()
    {
        $buffer = new class () extends CommandBuffer {
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

        $listeners = new CommandListeners($buffer);

        $event = new \stdClass();
        $event->command = 'migrate';
        $event->exitCode = 0;

        $listeners->onCommandStarting($event);
        $listeners->onCommandFinished($event);

        $this->assertSame('', $buffer->records[0]['parent_trace_id']);
        $this->assertNotSame('', $buffer->records[0]['trace_id']);
    }

    #[Test]
    public function a_scheduled_task_record_carries_an_empty_parent_rather_than_leaving_the_field_out()
    {
        $buffer = new class () extends ScheduledTaskBuffer {
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

        $listeners = new ScheduledTaskListeners($buffer);

        $task = new \stdClass();
        $task->expression = '* * * * *';
        $task->command = 'suite:task';

        $event = new \stdClass();
        $event->task = $task;

        $listeners->onScheduledTaskStarting($event);
        $listeners->onScheduledTaskFinished($event);

        ScheduledTaskListeners::$inFlight = 0;

        $this->assertSame('', $buffer->records[0]['parent_trace_id']);
        $this->assertNotSame('', $buffer->records[0]['trace_id']);
    }

    /**
     * The payload Laravel would push, hooks and all, without running anything.
     *
     * @return array<string, mixed>
     */
    private function payloadFor(object $job): array
    {
        $queue = new class () extends SyncQueue {
            /** @return array<string, mixed> */
            public function payloadFor(object $job): array
            {
                return json_decode($this->createPayload($job, 'default'), true);
            }
        };

        $queue->setContainer($this->app);

        return $queue->payloadFor($job);
    }

    private function job(string $rawBody): Job
    {
        return new SyncJob($this->app, $rawBody, 'redis', 'default');
    }

    private function processJob(string $rawBody): void
    {
        $monitor = new class () extends JobMonitor {
            public function __construct()
            {
            }

            public function trackJobStarted(Job $job, string $connectionName): void
            {
            }
        };

        (new JobEventSubscriber($monitor))->handleJobProcessing(
            new JobProcessing('redis', $this->job($rawBody))
        );
    }

    /**
     * The record the middleware buffers for a request that arrived with the
     * given traceparent, or with none at all.
     *
     * @return array<string, mixed>
     */
    private function recordForRequestWith(?string $traceparent): array
    {
        config(['larabug.requests.sample_rate' => 1.0]);

        // Skips the parent constructor, so no shutdown flush is registered and no client is needed.
        $buffer = new class () extends RequestBuffer {
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

        $middleware = new CaptureRequest(new RequestMonitor(), new Sampler(), $buffer);

        $request = Request::create('/orders', 'GET');

        if ($traceparent !== null) {
            $request->headers->set('traceparent', $traceparent);
        }

        $response = new HttpResponse('ok', 200);

        $middleware->handle($request, fn () => $response);
        $middleware->terminate($request, $response);

        return $buffer->records[0];
    }
}

class TraceablePropagationJob implements ShouldQueue
{
    public function handle(): void
    {
    }
}
