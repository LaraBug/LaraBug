<?php

namespace LaraBug\Queue;

use Illuminate\Support\Facades\Queue;

/**
 * A periodic "the workers are alive" report.
 *
 * Everything else this package sends is a report about something that happened:
 * an exception, a job that ran. None of that can distinguish a queue with no
 * work from a queue with no workers, because both send nothing at all. This is
 * the one message that says a worker exists, which is why it is sent on a
 * schedule rather than in response to anything.
 *
 * Where Horizon is installed it is asked directly, since it already knows its
 * supervisors, their process counts and how long each queue has been waiting.
 * Without it the queue driver is asked how much is waiting, which is less but
 * is still measured rather than guessed.
 */
class Heartbeat
{
    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'reported_at' => now()->toIso8601String(),
            'environment' => config('app.env'),
            'horizon' => $this->horizon(),
            'queues' => $this->queues(),
        ];
    }

    /**
     * What Horizon says about itself, or that it is not here.
     *
     * Every repository call is guarded: Horizon can be installed and its Redis
     * connection be down, and a heartbeat that throws is a heartbeat that never
     * arrives, which the panel would read as the workers being gone.
     *
     * @return array<string, mixed>
     */
    protected function horizon(): array
    {
        if (! class_exists('\Laravel\Horizon\Horizon')) {
            return ['installed' => false];
        }

        $report = [
            'installed' => true,
            'status' => null,
            'masters' => 0,
            'supervisors' => 0,
            'processes' => 0,
        ];

        try {
            $masters = app('\Laravel\Horizon\Contracts\MasterSupervisorRepository')->all();

            $report['masters'] = count($masters);

            // Horizon reports per master. One paused master is the whole thing
            // paused as far as a queue is concerned, so the least healthy status
            // wins rather than the first one read.
            foreach ($masters as $master) {
                $status = isset($master->status) ? $master->status : null;

                if ($report['status'] === null || $status === 'paused') {
                    $report['status'] = $status;
                }
            }

            if ($report['masters'] === 0) {
                $report['status'] = 'inactive';
            }
        } catch (\Throwable $e) {
            $report['status'] = 'unknown';
        }

        try {
            $supervisors = app('\Laravel\Horizon\Contracts\SupervisorRepository')->all();

            $report['supervisors'] = count($supervisors);

            foreach ($supervisors as $supervisor) {
                $processes = isset($supervisor->processes) ? (array) $supervisor->processes : [];

                $report['processes'] += array_sum($processes);

                $options = isset($supervisor->options) ? (array) $supervisor->options : [];

                // The two figures Horizon's own overview shows as "default" when
                // the supervisor has not been given them.
                $report['supervisor_options'][] = [
                    'name' => isset($supervisor->name) ? $supervisor->name : null,
                    'max_processes' => isset($options['maxProcesses']) ? $options['maxProcesses'] : null,
                    'max_runtime' => isset($options['maxTime']) ? $options['maxTime'] : null,
                    'max_throughput' => isset($options['maxJobs']) ? $options['maxJobs'] : null,
                    'balance' => isset($options['balance']) ? $options['balance'] : null,
                ];
            }
        } catch (\Throwable $e) {
            // Leave the counts at zero: the master status above is the part that
            // says whether anything is running.
        }

        return $report;
    }

    /**
     * How much is waiting on each queue, and how long it has been waiting.
     *
     * Horizon's workload already answers both per queue, including the wait it
     * measures itself. Without Horizon only the depth is available, and the
     * panel is left to work out the age from the jobs it has been sent.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function queues(): array
    {
        $fromHorizon = $this->horizonWorkload();

        if ($fromHorizon !== null) {
            return $fromHorizon;
        }

        $queues = [];

        foreach ($this->configuredQueues() as $entry) {
            $size = null;

            try {
                $size = Queue::connection($entry['connection'])->size($entry['queue']);
            } catch (\Throwable $e) {
                // A driver that cannot be counted, such as sync, or a broker
                // that is unreachable. Reporting null says "not measured",
                // which is not the same as reporting nothing waiting.
            }

            $queues[] = [
                'connection' => $entry['connection'],
                'queue' => $entry['queue'],
                'size' => $size,
                'wait' => null,
                'processes' => null,
            ];
        }

        return $queues;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    protected function horizonWorkload(): ?array
    {
        if (! class_exists('\Laravel\Horizon\Horizon')) {
            return null;
        }

        try {
            $workload = app('\Laravel\Horizon\Contracts\WorkloadRepository')->get();
        } catch (\Throwable $e) {
            return null;
        }

        $queues = [];

        foreach ($workload as $entry) {
            $entry = (array) $entry;

            $queues[] = [
                // Horizon names a queue by the connection's queues joined with
                // commas when a supervisor watches several; it is passed through
                // as reported rather than split, since that string is what its
                // own dashboard shows.
                'connection' => isset($entry['connection']) ? $entry['connection'] : null,
                'queue' => isset($entry['name']) ? $entry['name'] : null,
                'size' => isset($entry['length']) ? (int) $entry['length'] : null,
                // Seconds in Horizon, milliseconds everywhere in this payload.
                'wait' => isset($entry['wait']) ? (int) round($entry['wait'] * 1000) : null,
                'processes' => isset($entry['processes']) ? (int) $entry['processes'] : null,
            ];
        }

        return $queues;
    }

    /**
     * The queues to measure when there is no Horizon to ask.
     *
     * Configured explicitly, or the default connection's own queue, which is
     * the one an app that has never thought about this is using.
     *
     * @return array<int, array<string, string>>
     */
    protected function configuredQueues(): array
    {
        $configured = config('larabug.heartbeat.queues', []);

        if (! empty($configured)) {
            $queues = [];

            foreach ($configured as $entry) {
                if (is_string($entry)) {
                    $queues[] = ['connection' => config('queue.default'), 'queue' => $entry];

                    continue;
                }

                $queues[] = [
                    'connection' => isset($entry['connection']) ? $entry['connection'] : config('queue.default'),
                    'queue' => isset($entry['queue']) ? $entry['queue'] : 'default',
                ];
            }

            return $queues;
        }

        $connection = config('queue.default');

        return [[
            'connection' => $connection,
            'queue' => config('queue.connections.'.$connection.'.queue', 'default'),
        ]];
    }
}
