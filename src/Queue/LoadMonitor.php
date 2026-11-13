<?php

namespace LaraBug\Queue;

class LoadMonitor
{
    protected array $recentJobs = [];

    protected readonly int $highLoadThreshold;

    protected readonly int $lowLoadThreshold;

    protected readonly int $cooldownMinutes;

    protected ?int $batchingEnabledAt = null;

    protected bool $isEnabled = false;

    public function __construct()
    {
        $this->highLoadThreshold = config('larabug.jobs.auto_batch_threshold', 10); // jobs/min
        $this->lowLoadThreshold = config('larabug.jobs.auto_batch_disable_threshold', 5); // jobs/min
        $this->cooldownMinutes = config('larabug.jobs.auto_batch_cooldown', 5); // minutes
    }

    /**
     * Record a job dispatch and report whether batching should be enabled.
     */
    public function recordJob(): bool
    {
        $now = time();

        $this->recentJobs[] = $now;

        $this->recentJobs = array_filter(
            $this->recentJobs,
            fn ($timestamp) => $timestamp > ($now - 60)
        );

        $currentRate = count($this->recentJobs);

        if (! $this->isEnabled && $currentRate >= $this->highLoadThreshold) {
            $this->isEnabled = true;
            $this->batchingEnabledAt = $now;
        }

        // Disable only after the cooldown has passed, so batching does not flap
        // around the threshold.
        if ($this->isEnabled && $currentRate < $this->lowLoadThreshold) {
            $enabledDuration = $now - $this->batchingEnabledAt;

            if ($enabledDuration >= ($this->cooldownMinutes * 60)) {
                $this->isEnabled = false;
                $this->batchingEnabledAt = null;
            }
        }

        return $this->isEnabled;
    }

    public function isBatchingEnabled(): bool
    {
        return $this->isEnabled;
    }

    /**
     * Current job rate in jobs per minute.
     */
    public function getCurrentRate(): int
    {
        return count($this->recentJobs);
    }

    /**
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return [
            'current_rate' => $this->getCurrentRate(),
            'batching_enabled' => $this->isEnabled,
            'enabled_at' => $this->batchingEnabledAt,
            'high_threshold' => $this->highLoadThreshold,
            'low_threshold' => $this->lowLoadThreshold,
        ];
    }
}
