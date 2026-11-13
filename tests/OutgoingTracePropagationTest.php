<?php

namespace LaraBug\Tests;

use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Client as Guzzle;
use LaraBug\Requests\Traceparent;
use LaraBug\Requests\TraceContext;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use LaraBug\Http\Client as HttpClient;
use PHPUnit\Framework\Attributes\Test;

class OutgoingTracePropagationTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Http::fake();

        TraceContext::reset();
        TraceContext::id();
    }

    protected function tearDown(): void
    {
        TraceContext::reset();

        parent::tearDown();
    }

    #[Test]
    public function a_call_to_a_listed_host_carries_this_executions_trace()
    {
        config(['larabug.requests.trace_propagation_targets' => ['api.example.com']]);

        Http::get('https://api.example.com/orders');

        Http::assertSent(function (Request $request) {
            return Traceparent::parse($request->header('traceparent')[0] ?? null) === TraceContext::id();
        });
    }

    #[Test]
    public function a_call_to_any_other_host_carries_nothing()
    {
        config(['larabug.requests.trace_propagation_targets' => ['api.example.com']]);

        Http::get('https://api.stripe.com/v1/charges');
        Http::get('https://example.com/');
        Http::get('https://other.api.example.com/');

        $this->assertNotSentWithTraceparent();
    }

    #[Test]
    public function an_empty_list_sends_the_trace_nowhere()
    {
        config(['larabug.requests.trace_propagation_targets' => []]);

        Http::get('https://api.example.com/orders');

        $this->assertNotSentWithTraceparent();
    }

    #[Test]
    public function the_trace_is_sent_nowhere_by_default()
    {
        $this->assertSame([], config('larabug.requests.trace_propagation_targets'));

        Http::get('https://api.example.com/orders');

        $this->assertNotSentWithTraceparent();
    }

    #[Test]
    public function a_traceparent_the_call_already_carries_is_kept()
    {
        config(['larabug.requests.trace_propagation_targets' => ['api.example.com']]);

        $theirs = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

        Http::withHeaders(['traceparent' => $theirs])->get('https://api.example.com/orders');

        Http::assertSent(fn (Request $request) => $request->header('traceparent') === [$theirs]);
    }

    #[Test]
    public function no_trace_means_no_header()
    {
        config(['larabug.requests.trace_propagation_targets' => ['api.example.com']]);

        TraceContext::reset();

        Http::get('https://api.example.com/orders');

        $this->assertNotSentWithTraceparent();
        $this->assertFalse(TraceContext::started());
    }

    #[Test]
    public function a_wildcard_matches_every_subdomain_but_not_the_domain_itself()
    {
        config(['larabug.requests.trace_propagation_targets' => ['*.example.com']]);

        $this->assertSame(true, $this->carriesTraceparent('https://api.example.com/'));
        $this->assertSame(true, $this->carriesTraceparent('https://eu.api.example.com/'));
        $this->assertSame(false, $this->carriesTraceparent('https://example.com/'));
        $this->assertSame(false, $this->carriesTraceparent('https://badexample.com/'));
        $this->assertSame(false, $this->carriesTraceparent('https://api.example.com.evil.test/'));
    }

    #[Test]
    public function the_domain_itself_is_matched_once_it_is_listed_too()
    {
        config(['larabug.requests.trace_propagation_targets' => ['*.example.com', 'example.com']]);

        $this->assertSame(true, $this->carriesTraceparent('https://example.com/'));
        $this->assertSame(true, $this->carriesTraceparent('https://api.example.com/'));
    }

    #[Test]
    public function a_host_is_matched_case_insensitively_and_without_its_port()
    {
        config(['larabug.requests.trace_propagation_targets' => ['API.Example.com', '*.Internal.test']]);

        $this->assertSame(true, $this->carriesTraceparent('https://api.EXAMPLE.com:8443/orders'));
        $this->assertSame(true, $this->carriesTraceparent('http://billing.internal.TEST:8080/'));
    }

    #[Test]
    public function a_target_is_never_read_as_a_regular_expression()
    {
        config(['larabug.requests.trace_propagation_targets' => ['api.example.com', '.*']]);

        $this->assertSame(false, $this->carriesTraceparent('https://apixexample.com/'));
        $this->assertSame(false, $this->carriesTraceparent('https://anything.test/'));
    }

    #[Test]
    public function the_targets_are_read_from_a_comma_separated_environment_variable()
    {
        $this->assertSame([], $this->targetsFromEnvironment(null));
        $this->assertSame([], $this->targetsFromEnvironment(''));
        $this->assertSame(['api.example.com'], $this->targetsFromEnvironment('api.example.com'));
        $this->assertSame(
            ['api.example.com', '*.internal.example.com'],
            $this->targetsFromEnvironment(' api.example.com, *.internal.example.com ,,')
        );
    }

    #[Test]
    public function a_report_to_larabug_carries_only_its_own_traceparent()
    {
        config(['larabug.requests.trace_propagation_targets' => [
            parse_url(config('larabug.server'), PHP_URL_HOST),
        ]]);

        $sent = [];

        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{}')]));
        $stack->push(Middleware::history($sent));

        $client = new HttpClient('login', 'project');
        $client->setGuzzleHttpClient(new Guzzle(['handler' => $stack]));

        $client->report(['exception' => 'boom']);

        $traceparent = $sent[0]['request']->getHeader('traceparent');

        $this->assertCount(1, $traceparent);
        $this->assertSame(TraceContext::id(), Traceparent::parse($traceparent[0]));
        Http::assertNothingSent();
    }

    private function carriesTraceparent(string $url): bool
    {
        Http::get($url);

        [$request] = Http::recorded()->last();

        return $request->hasHeader('traceparent');
    }

    private function assertNotSentWithTraceparent(): void
    {
        Http::assertSent(fn () => true);
        Http::assertNotSent(fn (Request $request) => $request->hasHeader('traceparent'));
    }

    /** @return array<int, string> */
    private function targetsFromEnvironment(?string $value): array
    {
        $name = 'LB_TRACE_PROPAGATION_TARGETS';

        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        } else {
            putenv("{$name}={$value}");
            $_ENV[$name] = $_SERVER[$name] = $value;
        }

        try {
            $config = require __DIR__ . '/../config/larabug.php';
        } finally {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }

        return $config['requests']['trace_propagation_targets'];
    }
}
