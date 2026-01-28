<?php

namespace LaraBug\Queue;

class LoadMonitor
{
    protected array $recentJobs = [];
    protected int $highLoadThreshold;
    protected int $lowLoadThreshold;
    protected int $cooldownMinutes;
    protected ?int $batchingEnabledAt = null;
    protected bool $isEnabled = false;

    public function __construct()
    {
        $this->highLoadThreshold = config('larabug.jobs.auto_batch_threshold', 10); // jobs/min
        $this->lowLoadThreshold = config('larabug.jobs.auto_batch_disable_threshold', 5); // jobs/min
        $this->cooldownMinutes = config('larabug.jobs.auto_batch_cooldown', 5); // minutes
    }

    /**
     * Record a job dispatch and check if batching should be enabled
     */
    public function recordJob(): bool
    {
        $now = time();
        
        // Add current job
        $this->recentJobs[] = $now;
        
        // Clean old jobs (older than 60 seconds)
        $this->recentJobs = array_filter($this->recentJobs, function ($timestamp) use ($now) {
            return $timestamp > ($now - 60);
        });
        
        // Calculate current rate (jobs per minute)
        $currentRate = count($this->recentJobs);
        
        // Check if we should enable batching
        if (!$this->isEnabled && $currentRate >= $this->highLoadThreshold) {
            $this->isEnabled = true;
            $this->batchingEnabledAt = $now;
        }
        
        // Check if we should disable batching (cooldown period passed)
        if ($this->isEnabled && $currentRate < $this->lowLoadThreshold) {
            $enabledDuration = $now - $this->batchingEnabledAt;
            
            if ($enabledDuration >= ($this->cooldownMinutes * 60)) {
                $this->isEnabled = false;
                $this->batchingEnabledAt = null;
            }
        }
        
        return $this->isEnabled;
    }

    /**
     * Check if batching is currently enabled
     */
    public function isBatchingEnabled(): bool
    {
        return $this->isEnabled;
    }

    /**
     * Get current job rate (jobs per minute)
     */
    public function getCurrentRate(): int
    {
        return count($this->recentJobs);
    }

    /**
     * Get stats for monitoring
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
