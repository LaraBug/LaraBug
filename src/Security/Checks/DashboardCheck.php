<?php

namespace LaraBug\Security\Checks;

use Throwable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use LaraBug\Security\Middleware;
use LaraBug\Security\InstalledPackages;

/**
 * A dashboard a package serves under its own route, and whether a guest gets
 * in: Telescope, Horizon, Pulse.
 *
 * Decided from the application's own configuration, never by requesting the
 * dashboard. The route has to be registered, no authenticating middleware
 * may be in front of it, and the package's own gate has to let a guest
 * through, or be left out of the route's middleware altogether. The gate is
 * asked about a request without a user, the way it is asked about a visitor
 * who is not logged in.
 *
 * A gate that throws is reported as `unknown` and the dashboard as not
 * reachable: a finding has to be one the application can see for itself.
 */
abstract class DashboardCheck implements PostureCheck
{
    public function __construct(
        protected readonly InstalledPackages $packages,
        protected readonly Middleware $middleware,
    ) {
    }

    abstract protected function package(): string;

    /** @return class-string */
    abstract protected function provider(): string;

    abstract protected function routeName(): string;

    /** Whether the package's own switch is on. */
    abstract protected function switchedOn(): bool;

    /**
     * Whether the package's own authorisation runs in front of this route.
     *
     * @param  array<int, string>  $middleware
     */
    abstract protected function guardsRoute(array $middleware): bool;

    /** The package's gate, asked about a guest. */
    abstract protected function letsGuestsIn(Request $guest): bool;

    /**
     * @return array{
     *     installed: bool,
     *     enabled: bool,
     *     routed: bool,
     *     auth_middleware: bool,
     *     gate: string,
     *     reachable_without_auth: bool
     * }
     */
    public function collect(): array
    {
        $enabled = app()->getProviders($this->provider()) !== [] && $this->switchedOn();

        $route = $enabled ? $this->middleware->route($this->routeName()) : null;

        $middleware = $route === null ? [] : $this->middleware->forRoute($route);

        $authMiddleware = $this->middleware->authenticates($middleware);

        $gate = $route === null ? 'none' : $this->gate($route, $middleware);

        return [
            'installed' => $this->packages->has($this->package()),
            'enabled' => $enabled,
            'routed' => $route !== null,
            'auth_middleware' => $authMiddleware,
            'gate' => $gate,
            'reachable_without_auth' => $route !== null
                && ! $authMiddleware
                && in_array($gate, ['none', 'allows_guests'], true),
        ];
    }

    /**
     * `none` when the package's authorisation is not in front of the route,
     * else what it answers a guest: `allows_guests`, `denies_guests`, or
     * `unknown` when it threw.
     *
     * @param  array<int, string>  $middleware
     */
    protected function gate(Route $route, array $middleware): string
    {
        if (! $this->guardsRoute($middleware)) {
            return 'none';
        }

        try {
            return $this->letsGuestsIn($this->guestRequest($route)) ? 'allows_guests' : 'denies_guests';
        } catch (Throwable) {
            return 'unknown';
        }
    }

    protected function guestRequest(Route $route): Request
    {
        $request = Request::create('/'.trim(preg_replace('/\{[^}]*\}/', '', $route->uri()), '/'));

        $request->setUserResolver(fn () => null);

        return $request;
    }
}
