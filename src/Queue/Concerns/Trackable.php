<?php

namespace LaraBug\Queue\Concerns;

trait Trackable
{
    public bool $trackInLaraBug = true;

    public function track(): self
    {
        $this->trackInLaraBug = true;

        return $this;
    }

    public function dontTrack(): self
    {
        $this->trackInLaraBug = false;

        return $this;
    }

    public function shouldTrackInLaraBug(): bool
    {
        return $this->trackInLaraBug ?? true;
    }
}
