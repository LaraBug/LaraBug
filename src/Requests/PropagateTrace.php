<?php

namespace LaraBug\Requests;

use Psr\Http\Message\RequestInterface;

/**
 * Sends this execution's trace along with the application's own outgoing calls,
 * so a second monitored application that receives one continues the story as
 * its child instead of starting an unrelated one.
 *
 * Registered on Laravel's HTTP client, so a call made with Guzzle directly is
 * out of reach. Only hosts listed in requests.trace_propagation_targets get the
 * header: a trace id is ours, and has no business reaching every payment
 * provider and webhook the application talks to.
 */
class PropagateTrace
{
    public function __invoke(RequestInterface $request): RequestInterface
    {
        if ($request->hasHeader('traceparent')) {
            return $request;
        }

        if (! TraceContext::started()) {
            return $request;
        }

        if (! $this->isTarget($request->getUri()->getHost())) {
            return $request;
        }

        return $request->withHeader('traceparent', Traceparent::forCurrentTrace());
    }

    /**
     * An exact host, or `*.example.com` for any host below example.com but not
     * example.com itself. Plain string comparison on purpose: a pattern from
     * config is never handed to a regex engine.
     */
    protected function isTarget(string $host): bool
    {
        $host = strtolower($host);

        if ($host === '') {
            return false;
        }

        foreach ((array) config('larabug.requests.trace_propagation_targets', []) as $target) {
            $target = strtolower(trim((string) $target));

            if (str_starts_with($target, '*.')) {
                if (str_ends_with($host, substr($target, 1))) {
                    return true;
                }

                continue;
            }

            if ($host === $target) {
                return true;
            }
        }

        return false;
    }
}
