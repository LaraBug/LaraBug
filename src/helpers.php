<?php

use Illuminate\Foundation\Bus\PendingDispatch;

if (! function_exists('dispatch_tracked')) {
    /**
     * Dispatch a job with LaraBug tracking enabled.
     */
    function dispatch_tracked(object $job): PendingDispatch
    {
        $job->trackInLaraBug = true;

        return dispatch($job);
    }
}
