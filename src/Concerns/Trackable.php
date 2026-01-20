<?php

namespace LaraBug\Concerns;

/**
 * Add this trait to your job to enable LaraBug tracking
 * even when global job monitoring is disabled.
 *
 * Example:
 * class ImportantJob implements ShouldQueue
 * {
 *     use Trackable;
 *
 *     // Your job code...
 * }
 */
trait Trackable
{
    /**
     * Determine if this job should be tracked by LaraBug
     */
    public function shouldTrackInLaraBug(): bool
    {
        return true;
    }

    /**
     * Get custom tags for this job (optional)
     */
    public function larabugTags(): array
    {
        return [];
    }

    /**
     * Get custom metadata for this job (optional)
     */
    public function larabugMetadata(): array
    {
        return [];
    }
}
