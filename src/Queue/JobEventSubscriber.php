<?php

namespace LaraBug\Queue;

use Illuminate\Queue\Events\{JobProcessing, JobProcessed, JobFailed};

class JobEventSubscriber
{
    protected JobMonitor $monitor;

    protected array $timings = [];

    public function __construct(JobMonitor $monitor)
    {
        $this->monitor = $monitor;
    }

    public function subscribe($events): void
    {
        $events->listen(JobProcessing::class, [$this, 'handleJobProcessing']);
        $events->listen(JobProcessed::class, [$this, 'handleJobProcessed']);
        $events->listen(JobFailed::class, [$this, 'handleJobFailed']);
    }

    public function handleJobProcessing(JobProcessing $event): void
    {
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