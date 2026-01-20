<?php

namespace Illuminate\Foundation\Bus {

    /**
     * IDE Helper for LaraBug Queue Monitoring
     *
     * @method $this track(bool $track = true) Enable LaraBug tracking for this specific job
     */
    class PendingDispatch
    {
        //
    }

    /**
     * IDE Helper for LaraBug Queue Monitoring
     *
     * @method $this track(bool $track = true) Enable LaraBug tracking for all jobs in this chain
     */
    class PendingChain
    {
        //
    }

    /**
     * IDE Helper for LaraBug Queue Monitoring
     * Extends PendingDispatch, so ->track() is automatically available
     *
     * @method $this track(bool $track = true) Enable LaraBug tracking for this closure job
     */
    class PendingClosureDispatch extends PendingDispatch
    {
        //
    }
}

namespace Illuminate\Support\Facades {

    /**
     * @see \LaraBug\LaraBug
     */
    class LaraBug
    {
        //
    }
}
