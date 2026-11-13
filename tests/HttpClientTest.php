<?php

namespace LaraBug\Tests;

use LaraBug\Http\Client;
use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Client as GuzzleClient;
use PHPUnit\Framework\Attributes\Test;

/**
 * The client has to behave the same on Guzzle 7 and Guzzle 8, which is
 * stricter about the request options it is handed.
 */
class HttpClientTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    protected array $sent = [];

    #[Test]
    public function a_verify_ssl_left_null_still_sends_and_verifies()
    {
        config(['larabug.verify_ssl' => null]);

        $response = $this->client()->report(['exception' => 'Sent anyway']);

        $this->assertSame(200, $response?->getStatusCode());
        $this->assertCount(1, $this->sent);
        $this->assertTrue($this->sent[0]['options']['verify']);
    }

    #[Test]
    public function a_verify_ssl_switched_off_is_passed_on()
    {
        config(['larabug.verify_ssl' => false]);

        $this->client()->report(['exception' => 'Not verified']);

        $this->assertFalse($this->sent[0]['options']['verify']);
    }

    protected function client(): Client
    {
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"id":"test"}')]));
        $stack->push(Middleware::history($this->sent));

        return new Client('login-key', 'project-key', new GuzzleClient(['handler' => $stack]));
    }
}
