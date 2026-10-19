<?php

namespace LaraBug\Tests;

use ReflectionProperty;
use LaraBug\Http\Client;
use LaraBug\Queue\JobMonitor;
use LaraBug\Queue\EventBuffer;
use Workbench\App\Jobs\SendInvoice;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Queue;
use LaraBug\Tests\Mocks\MeteredClient;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Queue\Events\WorkerStopping;

/**
 * The queue buffer as the provider wires it, with only the client swapped for
 * one that records what would have been sent.
 */
class QueueIdleFlushTest extends TestCase
{
    protected MeteredClient $client;

    protected function getEnvironmentSetUp($app): void
    {
        $this->client = new MeteredClient();

        $app->extend(Client::class, fn () => $this->client);

        $app['config']->set('mail.default', 'array');
        $app['config']->set('larabug.project_key', 'project');
        $app['config']->set('larabug.jobs.auto_batch_threshold', 1);
    }

    #[Test]
    public function an_idle_worker_flushes_the_buffer_once_the_interval_has_passed()
    {
        $this->work();

        $this->client->assertRequestsSent(0);

        $this->intervalHasPassed();

        event(new Looping('redis', 'default'));

        $this->client->assertRequestsSent(1);
        $this->assertSame('queue_jobs_batch', $this->client->lastRequest()['type']);
        $this->assertSame(0, $this->buffer()->count());
    }

    #[Test]
    public function an_idle_worker_holds_the_buffer_before_the_interval_has_passed()
    {
        $this->work();

        event(new Looping('redis', 'default'));

        $this->client->assertRequestsSent(0);
        $this->assertSame(1, $this->buffer()->count());
    }

    #[Test]
    public function a_stopping_worker_flushes_the_buffer()
    {
        $this->work();

        event(new WorkerStopping());

        $this->client->assertRequestsSent(1);
        $this->assertSame(0, $this->buffer()->count());
    }

    protected function work(): void
    {
        Queue::connection('sync')->push(new SendInvoice());
    }

    protected function buffer(): EventBuffer
    {
        return (new ReflectionProperty(JobMonitor::class, 'buffer'))->getValue($this->app[JobMonitor::class]);
    }

    /**
     * Move the last flush into the past rather than sleeping through the interval.
     */
    protected function intervalHasPassed(): void
    {
        $buffer = $this->buffer();

        $interval = $this->app['config']->get('larabug.jobs.flush_interval');

        (new ReflectionProperty(EventBuffer::class, 'lastFlushTime'))->setValue($buffer, time() - $interval);
    }
}
