<?php

namespace LaraBug\Tests\Integration;

use Exception;
use LaraBug\LaraBug;
use RuntimeException;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * An application reports an exception the way Laravel reports one, and the
 * package turns it into a request to the configured server.
 */
class ExceptionReportingTest extends IntegrationTestCase
{
    #[Test]
    public function an_exception_reported_by_laravel_reaches_the_configured_server(): void
    {
        $this->report(new RuntimeException('the database went away'));

        $this->transport->assertSentCount(1);

        $sent = $this->transport->first();

        $this->assertSame('POST', $sent->method);
        $this->assertSame('https://larabug-app.test/api/log', $sent->url);
        $this->assertSame('Bearer integration-login-key', $sent->header('Authorization'));
        $this->assertSame('application/json', $sent->header('Content-Type'));
        $this->assertSame('LaraBug-Package', $sent->header('User-Agent'));

        // The report itself travels under `exception`, next to the user it
        // happened to. That envelope is the contract with the server.
        $this->assertSame('integration-project-key', $sent->payload('project'));
        $this->assertSame(RuntimeException::class, $sent->payload('exception.class'));
        $this->assertSame('the database went away', $sent->payload('exception.exception'));
        $this->assertSame('testing', $sent->payload('exception.environment'));
        $this->assertSame(__FILE__, $sent->payload('exception.file'));
    }

    #[Test]
    public function the_report_carries_the_source_around_each_frame(): void
    {
        $this->report(new RuntimeException('needs context'));

        $frames = $this->transport->first()->payload('exception.frames');

        $this->assertIsArray($frames);
        $this->assertNotEmpty($frames, 'A report with no frames is a stack trace nobody can read.');

        $first = $frames[0];

        $this->assertArrayHasKey('file', $first);
        $this->assertArrayHasKey('line', $first);
        $this->assertNotEmpty($first['code'] ?? [], 'The top frame should carry a window of source.');
    }

    #[Test]
    public function an_exception_named_in_the_except_list_is_never_sent(): void
    {
        $this->report(new NotFoundHttpException('no such page'));

        $this->transport->assertNothingSent();
    }

    #[Test]
    public function context_set_by_the_application_travels_with_the_report(): void
    {
        LaraBug::context(['tenant' => 'acme', 'plan' => 'staging']);

        $this->report(new Exception('with context'));

        $context = $this->transport->first()->payload('exception.custom_data');

        $this->assertSame('acme', $context['tenant'] ?? null);
        $this->assertSame('staging', $context['plan'] ?? null);
    }

    #[Test]
    public function the_same_exception_twice_is_only_sent_once_while_it_sleeps(): void
    {
        $this->app['config']->set('larabug.sleep', 60);

        $exception = new RuntimeException('a flood of one');

        $this->report($exception);
        $this->report($exception);

        $this->transport->assertSentCount(1);
    }

    /**
     * Report the way Laravel does, through the handler the package hooked into
     * rather than by calling the package directly.
     */
    protected function report(\Throwable $exception): void
    {
        $this->app->make(ExceptionHandler::class)->report($exception);
    }
}
