<?php

namespace LaraBug\Requests;

use Illuminate\Http\Request;

/**
 * Decides whether this request is recorded, and lets that decision change.
 *
 * Head-based: the call is made when the request arrives, because a decision
 * made at the end would mean paying to collect everything first. Two things
 * make that bearable.
 *
 * It is deferred. A trace has to start before routing, but the rules worth
 * writing are per route, so the decision is re-evaluated once the route is
 * known and the ignore list applies to route paths rather than to raw urls.
 *
 * And it can be revisited on failure. A request that threw is the one worth
 * having, so an unsampled request re-rolls at the exception rate rather than
 * being discarded because a coin landed the wrong way before anything went
 * wrong.
 */
class Sampler
{
    /** @var float */
    protected $rate;

    /** @var float */
    protected $exceptionRate;

    /** @var array<int, string> */
    protected $ignore;

    /** @var bool|null */
    protected $decision = null;

    public function __construct()
    {
        $this->rate = (float) config('larabug.requests.sample_rate', 0.1);
        $this->exceptionRate = (float) config('larabug.requests.exception_sample_rate', 1.0);
        $this->ignore = (array) config('larabug.requests.ignore_paths', []);
    }

    public function decide(Request $request): bool
    {
        if ($this->isIgnored('/'.ltrim($request->path(), '/'))) {
            return $this->decision = false;
        }

        return $this->decision = $this->roll($this->rate);
    }

    /**
     * Called once the route is known. An ignored route drops a request that was
     * already being recorded; nothing here promotes one that was not.
     */
    public function reconsider(string $routePath): bool
    {
        if ($this->decision === true && $this->isIgnored($routePath)) {
            $this->decision = false;
        }

        return (bool) $this->decision;
    }

    /**
     * The re-roll on failure. Only ever promotes.
     */
    public function reconsiderForException(): bool
    {
        if ($this->decision === true) {
            return true;
        }

        return $this->decision = $this->roll($this->exceptionRate);
    }

    public function decided(): bool
    {
        return (bool) $this->decision;
    }

    /**
     * The rate this request was kept at, which travels with the record: the
     * server divides by it to report what the application actually served, and
     * a rate changed next week must not rewrite this week's arithmetic.
     */
    public function rate(): float
    {
        return $this->rate > 0 && $this->rate <= 1 ? $this->rate : 1.0;
    }

    /**
     * The rate a failed record travels with, which is not the head rate.
     *
     * A 5xx is kept far more often than head sampling implies: whatever the
     * coin did on arrival, it re-rolls at the exception rate, so at the default
     * 1.0 every failure is kept. Dividing by the head rate would then weight a
     * failure by ten while it was really kept every time, counting ten failures
     * where there was one and wrecking the error rate. Its true keep-chance is
     * "head-sampled, or not and the exception re-roll kept it".
     */
    public function rateForException(): float
    {
        $head = $this->rate();
        $exception = $this->exceptionRate > 0 && $this->exceptionRate <= 1 ? $this->exceptionRate : 1.0;

        $effective = $head + (1 - $head) * $exception;

        return $effective > 0 && $effective <= 1 ? $effective : 1.0;
    }

    protected function roll(float $rate): bool
    {
        if ($rate >= 1) {
            return true;
        }

        if ($rate <= 0) {
            return false;
        }

        return (mt_rand() / mt_getrandmax()) <= $rate;
    }

    protected function isIgnored(string $path): bool
    {
        foreach ($this->ignore as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
