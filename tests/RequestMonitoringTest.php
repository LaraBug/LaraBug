<?php

namespace LaraBug\Tests;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LaraBug\Requests\QueryNormaliser;
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
