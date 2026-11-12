<?php

namespace LaraBug\Http;

class FlushResult
{
    /**
     * @param  int  $sent  Events the server accepted, now gone from the buffer.
     * @param  int  $discarded  Events removed without being accepted: too old,
     *                          unreadable, or refused with a 4xx that will
     *                          not get better.
     * @param  int  $remaining  Events still in the buffer afterwards.
     * @param  FlushStop|null  $stoppedBy  Why the flush ended early, null when
     *                                     it went through everything it found.
     */
    public function __construct(
        public readonly int $sent = 0,
        public readonly int $discarded = 0,
        public readonly int $remaining = 0,
        public readonly ?FlushStop $stoppedBy = null,
    ) {
    }
}
