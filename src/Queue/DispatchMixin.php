<?php

namespace LaraBug\Queue;

use Closure;

/**
 * Mixin for Laravel's dispatch classes, providing IDE autocomplete for ->track().
 *
 * @mixin \Illuminate\Foundation\Bus\PendingDispatch
 * @mixin \Illuminate\Foundation\Bus\PendingChain
 * @mixin \Illuminate\Foundation\Bus\PendingClosureDispatch
 */
class DispatchMixin
{
    /**
     * Enable LaraBug tracking for this specific job or chain.
     */
    public function track(): Closure
    {
        return function (bool $track = true) {
            $this->job->trackInLaraBug = $track;

            return $this;
        };
    }
}
