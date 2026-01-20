<?php

if (!function_exists('dispatch_tracked')) {
    /**
     * Dispatch a job with LaraBug tracking enabled
     * 
     * @param object $job
     * @return \Illuminate\Foundation\Bus\PendingDispatch
     */
    function dispatch_tracked($job)
    {
        $job->trackInLaraBug = true;
        return dispatch($job);
    }
}
