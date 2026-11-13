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
    public function shouldTrackInLaraBug(): bool
    {
        return true;
    }

    public function larabugTags(): array
    {
        return [];
    }

    public function larabugMetadata(): array
    {
        return [];
    }
}
