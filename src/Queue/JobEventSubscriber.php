<?php

namespace LaraBug\Queue;

use LaraBug\Mail\ExecutionMail;
use LaraBug\Mail\MailListeners;
use Illuminate\Queue\Jobs\SyncJob;
use LaraBug\Requests\TraceContext;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\WorkerStopping;

class JobEventSubscriber
{
    /** @var array<string, array{start: float, memory_start: int}> */
    protected array $timings = [];

    /**
     * The mail of the jobs in flight, keyed by the job object rather than its
     * id: a sync job has an empty id, and one sync job can dispatch another.
     *
     * @var array<int, ExecutionMail>
     */
    protected array $mail = [];

    public function __construct(
        protected readonly JobMonitor $monitor,
        protected readonly MailListeners $mailListeners = new MailListeners(),
    ) {
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(JobProcessing::class, [$this, 'handleJobProcessing']);
        $events->listen(JobProcessed::class, [$this, 'handleJobProcessed']);
        $events->listen(JobFailed::class, [$this, 'handleJobFailed']);
        $events->listen(Looping::class, [$this, 'handleLooping']);
        $events->listen(WorkerStopping::class, [$this, 'handleWorkerStopping']);
    }

    /**
     * Fired on every pass of the worker loop, an empty queue included. The
     * worker asks this event whether to carry on and pauses on a false, so
     * this must never return one.
     */
    public function handleLooping(Looping $event): void
    {
        $this->monitor->flushIfDue();
    }

    /**
     * Fired before a graceful stop and before the worker kills itself over a
     * timed out job, where no shutdown function would run.
     */
    public function handleWorkerStopping(WorkerStopping $event): void
    {
        $this->monitor->flush();
    }

    public function handleJobProcessing(JobProcessing $event): void
    {
        // Each job is its own unit of work, so each gets its own trace: a long
        // lived worker would otherwise stamp every job with the first job's id.
        // The trace that dispatched it is kept as the parent, which is the only
        // thing left joining the job to the request behind it.
        TraceContext::startChild(JobTracePayload::parentIdFor($event->job));

        $jobId = $event->job->getJobId() ?? spl_object_hash($event->job);

        $this->timings[$jobId] = [
            'start' => microtime(true),
            'memory_start' => memory_get_usage(true),
        ];

        // A worker runs one job at a time, so mail still open when the next
        // job off a real queue starts belongs to an attempt that ended with
        // neither a processed nor a failed event: one that threw and was
        // released for a retry. It goes with that attempt, which sends no
        // record, rather than onto the job that comes next. A sync job is the
        // exception: it runs inside whatever dispatched it, which may well be
        // another job still in progress.
        if (! $event->job instanceof SyncJob) {
            foreach ($this->mail as $stale) {
                $this->mailListeners->close($stale);
            }

            $this->mail = [];
        }

        // Opened for every job, including the ones the monitor will not
        // record: an ignored job's mail is not ours to keep, and leaving it
        // unclaimed would hand it to the worker command running the job.
        $this->mail[spl_object_id($event->job)] = $this->mailListeners->open();

        $this->monitor->trackJobStarted($event->job, $event->connectionName);
    }

    public function handleJobProcessed(JobProcessed $event): void
    {
        $jobId = $event->job->getJobId() ?? spl_object_hash($event->job);
        $timing = $this->timings[$jobId] ?? null;

        $duration = $timing ? (microtime(true) - $timing['start']) * 1000 : null;
        $memory = $timing ? memory_get_usage(true) - $timing['memory_start'] : null;

        $this->monitor->trackJobCompleted($event->job, $event->connectionName, $duration, $memory, $this->closeMail($event->job));

        unset($this->timings[$jobId]);
    }

    public function handleJobFailed(JobFailed $event): void
    {
        $jobId = $event->job->getJobId() ?? spl_object_hash($event->job);
        $timing = $this->timings[$jobId] ?? null;

        $duration = $timing ? (microtime(true) - $timing['start']) * 1000 : null;

        $this->monitor->trackJobFailed($event->job, $event->connectionName, $event->exception, $duration, $this->closeMail($event->job));

        unset($this->timings[$jobId]);
    }

    /**
     * The mail a job sent, with its unfinished sends swept in as failures.
     * Empty for a job whose processing was never seen, such as one failed
     * before it could be run.
     */
    protected function closeMail(Job $job): ExecutionMail
    {
        $key = spl_object_id($job);

        $mail = $this->mail[$key] ?? null;

        unset($this->mail[$key]);

        if ($mail === null) {
            return new ExecutionMail();
        }

        $this->mailListeners->close($mail);

        return $mail;
    }
}
