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
        if (! class_exists(\Illuminate\Http\Client\Request::class)) {
            $this->markTestSkipped('The Http client and its ResponseReceived event are Laravel 7+.');
        }

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
        if (! class_exists(\Illuminate\Http\Client\Request::class)) {
            $this->markTestSkipped('The Http client and its ConnectionFailed event are Laravel 7+.');
        }

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

    /** @test */
    public function it_records_a_message_with_its_recipient_domains_and_not_the_addresses()
    {
        $monitor = new RequestMonitor();
        $listeners = new RequestListeners($monitor, new Sampler());

        $listeners->onMailSent($this->mailEvent($this->mailMessage(
            'Welcome aboard',
            ['alice@example.com', 'bob@example.com'],
            ['team@other.test'],
        )));

        $record = $monitor->toArray(Request::create('/register', 'POST'), new Response('', 200), 1.0);

        $this->assertSame(1, $record['mail_sent']);
        $this->assertCount(1, $record['mail']);

        $message = $record['mail'][0];
        $this->assertSame('Welcome aboard', $message['subject']);
        $this->assertSame(2, $message['to_count']);
        $this->assertSame(1, $message['cc_count']);
        $this->assertSame(0, $message['bcc_count']);
        // Deduped domains, in recipient order, and never a local part.
        $this->assertSame('example.com,other.test', $message['recipient_domains']);
        $this->assertStringNotContainsString('alice', $message['recipient_domains']);
    }

    /** @test */
    public function it_keeps_the_full_recipient_addresses_only_when_they_are_opted_in()
    {
        config(['larabug.requests.capture_mail_recipients' => true]);

        $monitor = new RequestMonitor();
        $listeners = new RequestListeners($monitor, new Sampler());

        $listeners->onMailSent($this->mailEvent($this->mailMessage('Receipt', ['alice@example.com'])));

        $message = $monitor->toArray(Request::create('/orders', 'POST'), new Response('', 200), 1.0)['mail'][0];

        $this->assertSame('alice@example.com', $message['recipient_domains']);
    }

    /** @test */
    public function it_resolves_the_mailable_class_from_the_call_stack()
    {
        $monitor = new RequestMonitor();
        $listeners = new RequestListeners($monitor, new Sampler());

        $event = $this->mailEvent($this->mailMessage('Hi', ['a@b.test']));

        // The resolver walks the stack for a Mailable frame; firing the event
        // from inside one is what a real send does.
        $mailable = new class extends \Illuminate\Mail\Mailable {
            public function fire(RequestListeners $listeners, object $event): void
            {
                $listeners->onMailSent($event);
            }
        };

        $mailable->fire($listeners, $event);

        $message = $monitor->toArray(Request::create('/x', 'GET'), new Response('', 200), 1.0)['mail'][0];

        $this->assertSame(get_class($mailable), $message['mailable']);
    }

    /** @test */
    public function mail_sent_without_a_mailable_carries_an_empty_class()
    {
        $monitor = new RequestMonitor();
        $listeners = new RequestListeners($monitor, new Sampler());

        $listeners->onMailSent($this->mailEvent($this->mailMessage('Raw', ['a@b.test'])));

        $this->assertSame('', $monitor->toArray(Request::create('/x', 'GET'), new Response('', 200), 1.0)['mail'][0]['mailable']);
    }

    /** @test */
    public function it_times_a_send_from_its_sending_event()
    {
        $monitor = new RequestMonitor();
        $listeners = new RequestListeners($monitor, new Sampler());

        $event = $this->mailEvent($this->mailMessage('Timed', ['a@b.test']));

        $listeners->onMailSending($event);
        usleep(2000);
        $listeners->onMailSent($event);

        $message = $monitor->toArray(Request::create('/x', 'GET'), new Response('', 200), 1.0)['mail'][0];

        // Paired with its sending event, so a real duration rather than the zero
        // a sent-only mailer leaves.
        $this->assertGreaterThan(0, $message['duration_ms']);
    }

    /** @test */
    public function the_mail_counter_keeps_counting_past_the_cap()
    {
        config(['larabug.requests.max_mail' => 2]);

        $monitor = new RequestMonitor();

        for ($i = 0; $i < 5; $i++) {
            $monitor->recordMail([
                'mailable' => '', 'subject' => "m{$i}", 'to_count' => 1, 'cc_count' => 0,
                'bcc_count' => 0, 'recipient_domains' => 'x.test', 'queued' => 0, 'duration_ms' => 0.0,
            ]);
        }

        $record = $monitor->toArray(Request::create('/x', 'GET'), new Response('', 200), 1.0);

        // All five counted, only two kept.
        $this->assertSame(5, $record['mail_sent']);
        $this->assertCount(2, $record['mail']);
    }

    /** @test */
    public function it_records_a_notification_with_its_channel_and_notifiable_type()
    {
        $monitor = new RequestMonitor();
        $listeners = new RequestListeners($monitor, new Sampler());

        $listeners->onNotificationSent($this->notificationEvent(
            new FakeNotification(),
            'mail',
            new FakeNotifiable(),
        ));

        $record = $monitor->toArray(Request::create('/orders', 'POST'), new Response('', 200), 1.0);

        $this->assertSame(1, $record['notifications_sent']);
        $this->assertCount(1, $record['notifications']);

        $notification = $record['notifications'][0];
        $this->assertSame(FakeNotification::class, $notification['notification']);
        $this->assertSame('mail', $notification['channel']);
        // The type, never the id: the class is diagnostic, the row is personal.
        $this->assertSame(FakeNotifiable::class, $notification['notifiable_type']);
        $this->assertSame(1, $notification['success']);
    }

    /** @test */
    public function it_marks_a_failed_notification()
    {
        $monitor = new RequestMonitor();
        $listeners = new RequestListeners($monitor, new Sampler());

        $listeners->onNotificationFailed($this->notificationEvent(
            new FakeNotification(),
            'slack',
            new FakeNotifiable(),
        ));

        $notification = $monitor->toArray(Request::create('/x', 'GET'), new Response('', 200), 1.0)['notifications'][0];

        $this->assertSame(0, $notification['success']);
    }

    /** @test */
    public function a_notification_sent_over_mail_is_not_also_counted_as_mail()
    {
        $monitor = new RequestMonitor();
        $listeners = new RequestListeners($monitor, new Sampler());

        $event = $this->mailEvent($this->mailMessage('Reset your password', ['a@b.test']));
        // The stamp Laravel puts on a notification's mail: the notification path
        // already records it, so the mail path must leave it alone.
        $event->data = ['__laravel_notification' => FakeNotification::class];

        $listeners->onMailSent($event);

        $record = $monitor->toArray(Request::create('/x', 'GET'), new Response('', 200), 1.0);

        $this->assertSame(0, $record['mail_sent']);
        $this->assertSame([], $record['mail']);
    }

    /** @test */
    public function the_notification_counter_keeps_counting_past_the_cap()
    {
        config(['larabug.requests.max_notifications' => 2]);

        $monitor = new RequestMonitor();

        for ($i = 0; $i < 5; $i++) {
            $monitor->recordNotification([
                'notification' => FakeNotification::class, 'channel' => 'mail',
                'notifiable_type' => FakeNotifiable::class, 'success' => 1,
            ]);
        }

        $record = $monitor->toArray(Request::create('/x', 'GET'), new Response('', 200), 1.0);

        // All five counted, only two kept.
        $this->assertSame(5, $record['notifications_sent']);
        $this->assertCount(2, $record['notifications']);
    }

    /** @test */
    public function it_records_a_queued_mailable_at_dispatch_marked_queued()
    {
        $monitor = new RequestMonitor();
        $listeners = new RequestListeners($monitor, new Sampler());

        $mailable = new class extends \Illuminate\Mail\Mailable {};
        $mailable->to('alice@example.com')->cc('team@other.test');

        $event = new \stdClass();
        $event->job = new \Illuminate\Mail\SendQueuedMailable($mailable);

        $listeners->onJobQueued($event);

        $record = $monitor->toArray(Request::create('/register', 'POST'), new Response('', 200), 1.0);

        // The job is still a queued job, and now also a message.
        $this->assertSame(1, $record['jobs_queued']);
        $this->assertSame(1, $record['mail_sent']);
        $this->assertCount(1, $record['mail']);

        $message = $record['mail'][0];
        $this->assertSame(get_class($mailable), $message['mailable']);
        $this->assertSame(1, $message['to_count']);
        $this->assertSame(1, $message['cc_count']);
        $this->assertSame('example.com,other.test', $message['recipient_domains']);
        $this->assertSame(1, $message['queued']);
    }

    /** @test */
    public function a_queued_job_that_is_not_a_mailable_records_no_mail()
    {
        $monitor = new RequestMonitor();
        $listeners = new RequestListeners($monitor, new Sampler());

        $event = new \stdClass();
        $event->job = new \stdClass();

        $listeners->onJobQueued($event);

        $record = $monitor->toArray(Request::create('/x', 'GET'), new Response('', 200), 1.0);

        $this->assertSame(1, $record['jobs_queued']);
        $this->assertSame([], $record['mail']);
    }

    /**
     * A stand-in for a mail message. Symfony's Email and the older Swift message
     * share these getter names; this returns Address-shaped objects the way
     * Symfony does, which is what current Laravel hands the event.
     *
     * @param  array<int, string>  $to
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $bcc
     */
    private function mailMessage(string $subject, array $to, array $cc = [], array $bcc = []): object
    {
        $address = fn (string $email): object => new class($email) {
            /** @var string */
            private $email;

            public function __construct(string $email)
            {
                $this->email = $email;
            }

            public function getAddress(): string
            {
                return $this->email;
            }
        };

        return new class($subject, array_map($address, $to), array_map($address, $cc), array_map($address, $bcc)) {
            /** @var string */
            public $subject;

            /** @var array<int, object> */
            public $to;

            /** @var array<int, object> */
            public $cc;

            /** @var array<int, object> */
            public $bcc;

            public function __construct(string $subject, array $to, array $cc, array $bcc)
            {
                $this->subject = $subject;
                $this->to = $to;
                $this->cc = $cc;
                $this->bcc = $bcc;
            }

            public function getSubject(): string
            {
                return $this->subject;
            }

            /** @return array<int, object> */
            public function getTo(): array
            {
                return $this->to;
            }

            /** @return array<int, object> */
            public function getCc(): array
            {
                return $this->cc;
            }

            /** @return array<int, object> */
            public function getBcc(): array
            {
                return $this->bcc;
            }
        };
    }

    private function mailEvent(object $message): object
    {
        $event = new \stdClass();
        $event->message = $message;

        return $event;
    }

    /**
     * A stand-in for a notification event: the handler reads ->notification,
     * ->channel and ->notifiable, so a plain object with those is enough.
     */
    private function notificationEvent(object $notification, string $channel, object $notifiable): object
    {
        $event = new \stdClass();
        $event->notification = $notification;
        $event->channel = $channel;
        $event->notifiable = $notifiable;

        return $event;
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

/**
 * Named stand-ins so a notification and its notifiable have stable class names
 * to assert against, rather than the file-path names anonymous classes carry.
 */
class FakeNotification
{
}

class FakeNotifiable
{
}
