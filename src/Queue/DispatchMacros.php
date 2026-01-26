<?php

namespace LaraBug\Queue;

use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Foundation\Bus\PendingChain;

/**
 * Register ->track() macros on all Laravel dispatch classes
 * 
 * Supports:
 * - PendingDispatch (standard jobs)
 * - PendingClosureDispatch (closure jobs - inherits from PendingDispatch)
 * - PendingChain (job chains)
 */
class DispatchMacros
{
    /**
     * Register the ->track() macro on all dispatch types
     */
    public static function register(): void
    {
        self::registerOnPendingDispatch();
        self::registerOnPendingChain();
    }

    /**
     * Register ->track() on PendingDispatch
     */
    protected static function registerOnPendingDispatch(): void
    {
        if (method_exists(PendingDispatch::class, 'macro')) {
            PendingDispatch::macro('track', function (bool $track = true) {
                // Store tracking preference in the job's properties
                if (property_exists($this->job, 'trackInLaraBug')) {
                    $this->job->trackInLaraBug = $track;
                } else {
                    // Dynamically add the property
                    $this->job->trackInLaraBug = $track;
                }

                return $this;
            });
        }
    }

    /**
     * Register ->track() on PendingChain
     */
    protected static function registerOnPendingChain(): void
    {
        if (method_exists(PendingChain::class, 'macro')) {
            PendingChain::macro('track', function (bool $track = true) {
                // For chains, mark all jobs in the chain
                foreach ($this->chain as $job) {
                    if (is_object($job)) {
                        $job->trackInLaraBug = $track;
                    }
                }

                return $this;
            });
        }
    }
}
