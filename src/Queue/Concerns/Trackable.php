<?php

namespace LaraBug\Queue\Concerns;

trait Trackable
{
    /**
     * Indicates if this job should be tracked by LaraBug.
     *
     * @var bool
     */
    public $trackInLaraBug = true;

    /**
     * Enable tracking for this job in LaraBug.
     *
     * @return $this
     */
    public function track(): self
    {
        $this->trackInLaraBug = true;
        return $this;
    }

    /**
     * Disable tracking for this job in LaraBug.
     *
     * @return $this
     */
    public function dontTrack(): self
    {
        $this->trackInLaraBug = false;
        return $this;
    }

    /**
     * Check if this job should be tracked by LaraBug.
     *
     * @return bool
     */
    public function shouldTrackInLaraBug(): bool
    {
        return $this->trackInLaraBug ?? true;
    }
}
