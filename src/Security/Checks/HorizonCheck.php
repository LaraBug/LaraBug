<?php

namespace LaraBug\Security\Checks;

use Illuminate\Http\Request;

/**
 * Horizon's dashboard. It has no switch of its own, and its `Authenticate`
 * middleware is added by its controllers rather than by configuration, so
 * it always guards the route. It asks `Horizon::auth()`, which outside the
 * local environment comes down to the `viewHorizon` gate.
 */
class HorizonCheck extends DashboardCheck
{
    protected function package(): string
    {
        return 'laravel/horizon';
    }

    protected function provider(): string
    {
        return 'Laravel\Horizon\HorizonServiceProvider';
    }

    protected function routeName(): string
    {
        return 'horizon.index';
    }

    protected function switchedOn(): bool
    {
        return true;
    }

    protected function guardsRoute(array $middleware): bool
    {
        return true;
    }

    protected function letsGuestsIn(Request $guest): bool
    {
        return (bool) \Laravel\Horizon\Horizon::check($guest);
    }
}
