<?php

namespace LaraBug\Queue;

/**
 * Mixin for all Laravel dispatch classes
 * 
 * This provides IDE autocomplete support for ->track() method
 * 
 * @mixin \Illuminate\Foundation\Bus\PendingDispatch
 * @mixin \Illuminate\Foundation\Bus\PendingChain
 * @mixin \Illuminate\Foundation\Bus\PendingClosureDispatch
 */
class DispatchMixin
{
    /**
     * Enable LaraBug tracking for this specific job or chain
     *
     * @param bool $track
     * @return \Closure|DispatchMixin
     */
    public function track()
    {
        return function (bool $track = true) {
            if (property_exists($this->job, 'trackInLaraBug')) {
                $this->job->trackInLaraBug = $track;
            } else {
                $this->job->trackInLaraBug = $track;
            }

            return $this;
        };
    }
}
