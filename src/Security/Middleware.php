<?php

namespace LaraBug\Security;

use Throwable;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;

/**
 * The middleware the application runs, read from its configuration rather
 * than by sending it a request.
 */
class Middleware
{
    /**
     * Middleware that stops a guest before the route runs. Anything that
     * extends one of these, as an application's own `Authenticate` does,
     * counts too.
     *
     * @var array<int, class-string>
     */
    protected array $authenticating = [
        Authenticate::class,
        AuthenticateWithBasicAuth::class,
        Authorize::class,
    ];

    public function __construct(protected readonly Router $router)
    {
    }

    /**
     * The global middleware, as class names. Resolving the kernel is what
     * applies `withMiddleware()` from bootstrap/app.php, which a console
     * process never does on its own.
     *
     * @return array<int, string>
     */
    public function global(): array
    {
        $kernel = app(HttpKernel::class);

        if (! method_exists($kernel, 'getGlobalMiddleware')) {
            return [];
        }

        return array_values(array_filter($kernel->getGlobalMiddleware(), 'is_string'));
    }

    /**
     * The first global middleware that is, or extends, the given class.
     */
    public function globalInstanceOf(string $class): ?string
    {
        foreach ($this->global() as $middleware) {
            if ($this->is($middleware, $class)) {
                return $this->className($middleware);
            }
        }

        return null;
    }

    public function route(string $name): ?Route
    {
        // Resolved first, so the kernel's groups and aliases are on the router.
        app(HttpKernel::class);

        $routes = $this->router->getRoutes();

        // A route named after it was added is only found once the names are
        // looked up again. A cached route collection has no such step.
        if (method_exists($routes, 'refreshNameLookups')) {
            $routes->refreshNameLookups();
        }

        return $routes->getByName($name);
    }

    /**
     * A route's own middleware with its groups and aliases resolved. Not the
     * middleware its controller adds, since that means building the
     * controller.
     *
     * @return array<int, string>
     */
    public function forRoute(Route $route): array
    {
        return array_values(array_filter(
            $this->router->resolveMiddleware($route->middleware(), $route->excludedMiddleware()),
            'is_string',
        ));
    }

    /**
     * Whether any of this middleware sends a guest away.
     *
     * @param  array<int, string>  $middleware
     */
    public function authenticates(array $middleware): bool
    {
        foreach ($middleware as $name) {
            foreach ($this->authenticating as $class) {
                if ($this->is($name, $class)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $middleware
     */
    public function contains(array $middleware, string $class): bool
    {
        foreach ($middleware as $name) {
            if ($this->is($name, $class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a middleware, parameters and all, is the given class or extends
     * it. A name that is no class, such as an alias nobody defined, is not.
     */
    protected function is(string $middleware, string $class): bool
    {
        $name = $this->className($middleware);

        if (strcasecmp($name, $class) === 0) {
            return true;
        }

        try {
            return class_exists($name) && is_a($name, $class, true);
        } catch (Throwable) {
            return false;
        }
    }

    protected function className(string $middleware): string
    {
        return ltrim(explode(':', $middleware, 2)[0], '\\');
    }
}
