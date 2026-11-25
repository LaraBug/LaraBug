<?php

namespace LaraBug\Security\Checks;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Pulse's dashboard. Its `Authorize` middleware sits in `pulse.middleware`,
 * where an application can take it out, and asks the `viewPulse` gate.
 */
class PulseCheck extends DashboardCheck
{
    protected function package(): string
    {
        return 'laravel/pulse';
    }

    protected function provider(): string
    {
        return 'Laravel\Pulse\PulseServiceProvider';
    }

    protected function routeName(): string
    {
        return 'pulse';
    }

    protected function switchedOn(): bool
    {
        return (bool) config('pulse.enabled', true);
    }

    protected function guardsRoute(array $middleware): bool
    {
        return $this->middleware->contains($middleware, 'Laravel\Pulse\Http\Middleware\Authorize');
    }

    protected function letsGuestsIn(Request $guest): bool
    {
        return Gate::forUser(null)->check('viewPulse');
    }
}
