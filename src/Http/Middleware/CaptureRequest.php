<?php

namespace LaraBug\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LaraBug\Requests\RequestBuffer;
use LaraBug\Requests\RequestMonitor;
use LaraBug\Requests\Sampler;
use LaraBug\Requests\TraceContext;
use Throwable;

/**
 * Where a request's stage boundaries are marked, and where its record is
 * handed over.
 *
 * Global and terminable. The stages either side of this call are the middleware
 * stack, the stage inside it is the action, and terminate is what runs after
 * the response has already gone to the client — which is also why the record is
 * assembled there rather than before the response is returned. Nothing the
 * customer waits for happens in this file.
 */
class CaptureRequest
{
    /** @var RequestMonitor */
    protected $monitor;

    /** @var Sampler */
    protected $sampler;

    /** @var RequestBuffer */
    protected $buffer;

    public function __construct(RequestMonitor $monitor, Sampler $sampler, RequestBuffer $buffer)
    {
        $this->monitor = $monitor;
        $this->sampler = $sampler;
        $this->buffer = $buffer;
    }

    public function handle(Request $request, Closure $next)
    {
        try {
            $this->sampler->decide($request);

            // A new request is a new trace. Reset first, because under Octane
            // this process already served one and would otherwise hand its id
            // to every request for the life of the worker.
            //
            // Touched at the start so every log line and exception from here on
            // carries the same id, whether or not this request ends up sampled:
            // deciding late would leave the earliest lines unstamped.
            TraceContext::reset();
            TraceContext::id();

            $this->monitor->mark('booted');
            $this->monitor->mark('action_start');
        } catch (Throwable $e) {
            // A monitor that cannot start is not a reason to refuse the
            // request. Everything below reads marks that simply will not exist.
        }

        $response = $next($request);

        try {
            $this->monitor->mark('action_end');
            $this->monitor->mark('response_prepared');
        } catch (Throwable $e) {
        }

        return $response;
    }

    public function terminate(Request $request, $response): void
    {
        if (! $this->sampler->decided()) {
            return;
        }

        try {
            $this->monitor->mark('response_sent');
            $this->monitor->mark('terminating');

            $record = $this->monitor->toArray($request, $response, $this->sampler->rate());

            $this->monitor->mark('terminated');

            $this->buffer->add($record);
        } catch (Throwable $e) {
            // Same bargain as everywhere else in this package: losing the
            // record is acceptable, breaking the application is not.
        }
    }
}
