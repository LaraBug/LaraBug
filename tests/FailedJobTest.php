<?php

namespace LaraBug\Tests;

use LaraBug\Http\Client;
use LaraBug\Requests\TraceContext;
use Illuminate\Support\Facades\Queue;
use Workbench\App\Jobs\RejectInvoice;
use LaraBug\Tests\Mocks\MeteredClient;
use PHPUnit\Framework\Attributes\Test;
use Orchestra\Testbench\Attributes\WithMigration;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

/**
 * A job that fails itself with $this->fail() without throwing gets the
 * processed event after the failed one, though it did not complete.
 */
#[WithMigration('queue')]
class FailedJobTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected MeteredClient $client;

    protected function getEnvironmentSetUp($app): void
    {
        $this->client = new MeteredClient();

        $app->extend(Client::class, fn () => $this->client);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database.connection', 'testing');
        $app['config']->set('queue.failed.database', 'testing');
        $app['config']->set('larabug.project_key', 'project');
    }

    protected function tearDown(): void
    {
        TraceContext::reset();

        parent::tearDown();
    }

    #[Test]
    public function a_job_that_fails_itself_is_only_reported_as_failed()
    {
        Queue::push(new RejectInvoice());

        $this->work();

        [$failed] = $this->records();

        $this->assertSame(['failed'], array_column($this->records(), 'status'));
        $this->assertSame('Invoice has no customer', $failed['exception']['message']);
    }

    protected function work(): void
    {
        $this->artisan('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--tries' => 3,
        ])->run();
    }

    /** @return array<int, array<string, mixed>> */
    protected function records(): array
    {
        return array_column($this->client->requests, 'job');
    }
}
