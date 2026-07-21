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
        // A failure that head sampling dropped is worth having anyway: re-roll
        // it at the exception rate and keep it if it now lands. Only for a 5xx,
        // and only when the monitor actually started this request, so a
        // terminate after a handle that could not is left alone rather than
        // buffering a record with no stages behind it.
        if (! $this->sampler->decided()
            && ! ($this->isServerError($response)
                && $this->monitor->started()
                && $this->sampler->reconsiderForException())) {
            return;
        }

        try {
            $this->monitor->mark('response_sent');
            $this->monitor->mark('terminating');

            // A 5xx carries its effective keep-rate, not the head rate: it is
            // kept far more often than head sampling implies, so weighting it by
            // the head rate would count each failure many times over.
            $rate = $this->isServerError($response)
                ? $this->sampler->rateForException()
                : $this->sampler->rate();

            $record = $this->monitor->toArray($request, $response, $rate);

            $this->monitor->mark('terminated');

            $this->buffer->add($record);
        } catch (Throwable $e) {
            // Same bargain as everywhere else in this package: losing the
            // record is acceptable, breaking the application is not.
        }
    }

    /**
     * @param  mixed  $response
     */
    private function isServerError($response): bool
    {
        return method_exists($response, 'getStatusCode') && $response->getStatusCode() >= 500;
    }
}
