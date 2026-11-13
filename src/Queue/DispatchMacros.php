<?php

namespace LaraBug\Queue;

use Illuminate\Foundation\Bus\PendingChain;
use Illuminate\Foundation\Bus\PendingDispatch;

/**
 * Registers ->track() macros on PendingDispatch (which PendingClosureDispatch
 * inherits from) and PendingChain.
 */
class DispatchMacros
{
    public static function register(): void
    {
        self::registerOnPendingDispatch();
        self::registerOnPendingChain();
    }

    protected static function registerOnPendingDispatch(): void
    {
        if (! method_exists(PendingDispatch::class, 'macro')) {
            return;
        }

        PendingDispatch::macro('track', function (bool $track = true) {
            $this->job->trackInLaraBug = $track;

            return $this;
        });
    }

    protected static function registerOnPendingChain(): void
    {
        if (! method_exists(PendingChain::class, 'macro')) {
            return;
        }

        PendingChain::macro('track', function (bool $track = true) {
            foreach ($this->chain as $job) {
                if (is_object($job)) {
                    $job->trackInLaraBug = $track;
                }
            }

            return $this;
        });
    }
}
