<?php

namespace LaraBug\Security\Checks;

use Illuminate\Http\Request;

/**
 * Telescope's dashboard. Its `Authorize` middleware sits in
 * `telescope.middleware`, where an application can take it out, and asks
 * `Telescope::auth()`, which outside the local environment comes down to the
 * `viewTelescope` gate.
 */
class TelescopeCheck extends DashboardCheck
{
    protected function package(): string
    {
        return 'laravel/telescope';
    }

    protected function provider(): string
    {
        return 'Laravel\Telescope\TelescopeServiceProvider';
    }

    protected function routeName(): string
    {
        return 'telescope';
    }

    protected function switchedOn(): bool
    {
        return (bool) config('telescope.enabled', true);
    }

    protected function guardsRoute(array $middleware): bool
    {
        return $this->middleware->contains($middleware, 'Laravel\Telescope\Http\Middleware\Authorize');
    }

    protected function letsGuestsIn(Request $guest): bool
    {
        return (bool) \Laravel\Telescope\Telescope::check($guest);
    }
}
