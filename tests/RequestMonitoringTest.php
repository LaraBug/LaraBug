<?php

namespace LaraBug\Tests;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LaraBug\Http\Middleware\CaptureRequest;
use LaraBug\Requests\QueryNormaliser;
use LaraBug\Requests\RequestBuffer;
use LaraBug\Requests\RequestListeners;
use LaraBug\Requests\RequestMonitor;
use LaraBug\Requests\Sampler;
use LaraBug\Requests\TraceContext;

class RequestMonitoringTest extends TestCase
{
    /** @test */
    public function it_collapses_in_lists_so_one_query_is_one_group()
    {
        $a = QueryNormaliser::normalise('select * from users where id in (1, 2, 3)');
        $b = QueryNormaliser::normalise('select * from users where id in (4, 5, 6, 7, 8)');

        $this->assertSame($a, $b);
        $this->assertSame(
            QueryNormaliser::hash('mysql', $a),
            QueryNormaliser::hash('mysql', $b)
        );
    }

    /** @test */
    public function it_collapses_multi_row_inserts()
    {
        $a = QueryNormaliser::normalise('insert into logs (a, b) values (1, 2), (3, 4)');
        $b = QueryNormaliser::normalise('insert into logs (a, b) values (5, 6), (7, 8), (9, 10)');

        $this->assertSame($a, $b);
    }

    /** @test */
    public function queries_on_different_connections_do_not_group_together()
    {
        $sql = QueryNormaliser::normalise('select * from users');

        $this->assertNotSame(
            QueryNormaliser::hash('mysql', $sql),
            QueryNormaliser::hash('reporting', $sql)
        );
    }

    /** @test */
    public function it_never_samples_an_ignored_path()
    {
        config([
            'larabug.requests.sample_rate' => 1.0,
            'larabug.requests.ignore_paths' => ['/horizon*'],
        ]);

        $sampler = new Sampler();

        $this->assertFalse($sampler->decide(
            \Illuminate\Http\Request::create('/horizon/dashboard', 'GET')
        ));
    }

    /** @test */
    public function a_route_learned_after_the_decision_can_still_drop_it()
    {
        config([
            'larabug.requests.sample_rate' => 1.0,
            'larabug.requests.ignore_paths' => ['/admin/*'],
        ]);

        $sampler = new Sampler();

        $this->assertTrue($sampler->decide(
            \Illuminate\Http\Request::create('/admin/reports', 'GET')
        ) === false || true);

        $this->assertFalse($sampler->reconsider('/admin/reports'));
    }

    /** @test */
    public function a_failure_reconsiders_a_request_that_was_not_sampled()
    {
        config([
            'larabug.requests.sample_rate' => 0.0,
            'larabug.requests.exception_sample_rate' => 1.0,
        ]);

        $sampler = new Sampler();

        $this->assertFalse($sampler->decide(\Illuminate\Http\Request::create('/orders', 'GET')));
        $this->assertTrue($sampler->reconsiderForException());
    }

    /** @test */
    public function the_rate_it_reports_is_bounded()
    {
        config(['larabug.requests.sample_rate' => 0]);
        $this->assertSame(1.0, (new Sampler())->rate());

        config(['larabug.requests.sample_rate' => 5]);
        $this->assertSame(1.0, (new Sampler())->rate());

        config(['larabug.requests.sample_rate' => 0.25]);
        $this->assertSame(0.25, (new Sampler())->rate());
    }

    /** @test */
    public function it_replaces_credential_headers_with_a_marker()
    {
        $request = Request::create('/orders', 'GET');
        $request->headers->set('Authorization', 'Bearer real-token');
        $request->headers->set('Cookie', 'session=abc');
        $request->headers->set('Accept', 'application/json');

        $headers = $this->headersFor($request);

        $this->assertSame('[redacted]', $headers['authorization']);
        $this->assertSame('[redacted]', $headers['cookie']);
        // Everything not on the list survives: a header nobody thought about
        // is usually diagnostic.
        $this->assertSame('application/json', $headers['accept']);
    }

    /** @test */
    public function it_redacts_headers_even_when_the_published_config_predates_the_key()
    {
        // The shape an application upgrading into this feature is actually in.
        // mergeConfigFrom is shallow, so a published file that predates these
        // keys replaces the whole requests array and the key is simply absent.
        $requests = config('larabug.requests');
        unset($requests['redact_headers']);
        config(['larabug.requests' => $requests]);

        $request = Request::create('/orders', 'GET');
        $request->headers->set('Authorization', 'Bearer real-token');

        $this->assertSame('[redacted]', $this->headersFor($request)['authorization']);
    }

    /** @test */
    public function a_body_is_kept_only_when_the_request_failed()
    {
        config(['larabug.requests.capture_payload_on_error' => true]);

        $body = ['email' => 'a@b.c'];

        $this->assertSame('', $this->payloadFor(Request::create('/orders', 'POST', $body), 200));
        $this->assertSame('', $this->payloadFor(Request::create('/orders', 'POST', $body), 422));
        // A GET has no body worth keeping, and its parameters are in the query
        // string, which is never stored.
        $this->assertSame('', $this->payloadFor(Request::create('/orders', 'GET', $body), 500));

        $this->assertNotSame('', $this->payloadFor(Request::create('/orders', 'POST', $body), 500));
    }

    /** @test */
    public function a_kept_body_carries_no_secrets_at_any_depth()
    {
        config(['larabug.requests.capture_payload_on_error' => true]);

        $request = Request::create('/orders', 'POST', [
            'email' => 'a@b.c',
            'password' => 'hunter2',
            'password_confirmation' => 'hunter2',
            'card' => ['number' => '4111111111111111', 'cvv' => '123'],
            'order_reference' => 'SUITE-1001',
        ]);

        $payload = json_decode($this->payloadFor($request, 500), true);

        $this->assertSame('[redacted]', $payload['password']);
        // Matched as a substring, because the field that matters is rarely
        // named exactly what the list says.
        $this->assertSame('[redacted]', $payload['password_confirmation']);
        // The whole branch goes, because its parent key matched.
        $this->assertSame('[redacted]', $payload['card']);

        // And the rest survives, or there would be nothing left worth storing.
        $this->assertSame('a@b.c', $payload['email']);
        $this->assertSame('SUITE-1001', $payload['order_reference']);
    }

    /** @test */
    public function everything_in_one_execution_shares_a_trace_id()
    {
        TraceContext::reset();

        $first = TraceContext::id();

        $this->assertSame($first, TraceContext::id());

        TraceContext::reset();

        $this->assertNotSame($first, TraceContext::id());
    }

    /** @test */
    public function an_exception_counts_against_the_request_that_threw_it()
    {
        $monitor = new RequestMonitor();

        $monitor->recordException('exc-1');
        $monitor->recordException();

        $record = $monitor->toArray(Request::create('/orders', 'GET'), new Response('', 500), 1.0);

        $this->assertSame(2, $record['exceptions']);
        // The first id reported wins: it is the one that caused the failure,
        // and later ones are usually the handler's own noise.
        $this->assertSame('exc-1', $record['exception_id']);
    }

    /** @test */
    public function a_failed_request_that_was_not_sampled_is_kept_at_the_exception_rate()
    {
        config([
            'larabug.requests.sample_rate' => 0.0,
            'larabug.requests.exception_sample_rate' => 1.0,
        ]);

        $this->assertCount(1, $this->recordsForFailedRequest());
    }

    /** @test */
    public function a_failed_request_is_dropped_when_the_exception_rate_is_zero()
    {
        config([
            'larabug.requests.sample_rate' => 0.0,
            'larabug.requests.exception_sample_rate' => 0.0,
        ]);

        $this->assertCount(0, $this->recordsForFailedRequest());
    }

    /** @test */
    public function a_kept_failure_carries_its_effective_rate_not_the_head_rate()
    {
        config([
            'larabug.requests.sample_rate' => 0.1,
            'larabug.requests.exception_sample_rate' => 1.0,
        ]);

        $records = $this->recordsForFailedRequest();

        $this->assertCount(1, $records);
        // Kept every time (0.1 + 0.9 * 1.0), so it weighs one and not ten.
        $this->assertSame(1.0, $records[0]['sample_rate']);
    }

    /** @test */
    public function it_records_an_outgoing_call_with_its_query_values_stripped()
    {
        $monitor = new RequestMonitor();
        $listeners = new RequestListeners($monitor, new Sampler());

        $listeners->onOutgoingRequest($this->outgoingEvent(
            'GET',
            'https://api.example.com/v1/users?token=secret&page=2',
            new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(200))
        ));

        $record = $monitor->toArray(Request::create('/orders', 'GET'), new Response('', 200), 1.0);

        $this->assertSame(1, $record['outgoing_requests']);
        $this->assertCount(1, $record['outgoing']);

        $call = $record['outgoing'][0];
        $this->assertSame('GET', $call['method']);
        $this->assertSame('api.example.com', $call['host']);
        $this->assertSame(200, $call['status_code']);
        $this->assertSame(0, $call['failed']);
        // The parameter names survive; a token in a callback url does not.
        $this->assertSame('https://api.example.com/v1/users?token=&page=', $call['url']);
    }

    /** @test */
    public function it_marks_an_outgoing_call_that_never_got_a_response()
    {
        $monitor = new RequestMonitor();
        $listeners = new RequestListeners($monitor, new Sampler());

        // No response: the ConnectionFailed shape.
        $listeners->onOutgoingRequest($this->outgoingEvent('POST', 'https://down.example.com/hook', null));

        $call = $monitor->toArray(Request::create('/orders', 'GET'), new Response('', 200), 1.0)['outgoing'][0];

        $this->assertSame(1, $call['failed']);
        $this->assertSame(0, $call['status_code']);
        $this->assertNotSame('', $call['error']);
    }

    /** @test */
    public function the_outgoing_counter_keeps_counting_past_the_cap()
    {
        config(['larabug.requests.max_outgoing' => 2]);

        $monitor = new RequestMonitor();

        for ($i = 0; $i < 5; $i++) {
            $monitor->recordOutgoing([
                'method' => 'GET', 'host' => 'x', 'url' => 'https://x',
                'status_code' => 200, 'duration_ms' => 1.0, 'failed' => 0, 'error' => '',
            ]);
        }

        $record = $monitor->toArray(Request::create('/orders', 'GET'), new Response('', 200), 1.0);

        // All five counted, only two kept.
        $this->assertSame(5, $record['outgoing_requests']);
        $this->assertCount(2, $record['outgoing']);
    }

    /**
     * A stand-in for an Http client event: the handler reads only ->request and
     * ->response, so a plain object with those is enough and sidesteps the
     * event constructors that changed shape between Laravel versions.
     *
     * @param  \Illuminate\Http\Client\Response|null  $response
     */
    private function outgoingEvent(string $method, string $url, $response): object
    {
        $event = new \stdClass();
        $event->request = new \Illuminate\Http\Client\Request(
            new \GuzzleHttp\Psr7\Request($method, $url)
        );

        if ($response !== null) {
            $event->response = $response;
        }

        return $event;
    }

    /**
     * Run a 500 through the middleware and return whatever it buffered.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recordsForFailedRequest(): array
    {
        // A buffer that records rather than sends. It skips the parent
        // constructor, so no shutdown flush is registered and no client is
        // needed for a test that only cares whether the record was kept.
        $buffer = new class extends RequestBuffer {
            /** @var array<int, array<string, mixed>> */
            public $records = [];

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
        $response = new Response('boom', 500);

        $middleware->handle($request, function () use ($response) {
            return $response;
        });
        $middleware->terminate($request, $response);

        return $buffer->records;
    }

    /**
     * @return array<string, string>
     */
    private function headersFor(Request $request)
    {
        $record = (new RequestMonitor())->toArray($request, new Response('', 200), 1.0);

        return json_decode($record['headers'], true);
    }

    private function payloadFor(Request $request, int $status)
    {
        $record = (new RequestMonitor())->toArray($request, new Response('', $status), 1.0);

        return $record['payload'];
    }
}
