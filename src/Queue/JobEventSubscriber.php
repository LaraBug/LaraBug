<?php

namespace LaraBug\Queue;

use LaraBug\Requests\TraceContext;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Contracts\Events\Dispatcher;

class JobEventSubscriber
{
    /** @var array<string, array{start: float, memory_start: int}> */
    protected array $timings = [];

    public function __construct(
        protected readonly JobMonitor $monitor,
    ) {
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(JobProcessing::class, [$this, 'handleJobProcessing']);
        $events->listen(JobProcessed::class, [$this, 'handleJobProcessed']);
        $events->listen(JobFailed::class, [$this, 'handleJobFailed']);
    }

    public function handleJobProcessing(JobProcessing $event): void
    {
        // Each job is its own unit of work, so each gets its own trace: a long
        // lived worker would otherwise stamp every job with the first job's id.
        TraceContext::reset();

        $jobId = $event->job->getJobId() ?? spl_object_hash($event->job);

        $this->timings[$jobId] = [
            'start' => microtime(true),
            'memory_start' => memory_get_usage(true),
        ];

        $this->monitor->trackJobStarted($event->job, $event->connectionName);
    }

    public function handleJobProcessed(JobProcessed $event): void
    {
        $jobId = $event->job->getJobId() ?? spl_object_hash($event->job);
        $timing = $this->timings[$jobId] ?? null;

        $duration = $timing ? (microtime(true) - $timing['start']) * 1000 : null;
        $memory = $timing ? memory_get_usage(true) - $timing['memory_start'] : null;

        $this->monitor->trackJobCompleted($event->job, $event->connectionName, $duration, $memory);

        unset($this->timings[$jobId]);
    }

    public function handleJobFailed(JobFailed $event): void
    {
        $jobId = $event->job->getJobId() ?? spl_object_hash($event->job);
        $timing = $this->timings[$jobId] ?? null;

        $duration = $timing ? (microtime(true) - $timing['start']) * 1000 : null;

        $this->monitor->trackJobFailed($event->job, $event->connectionName, $event->exception, $duration);

        unset($this->timings[$jobId]);
    }
}
