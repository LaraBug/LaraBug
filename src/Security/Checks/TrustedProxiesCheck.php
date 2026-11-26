<?php

namespace LaraBug\Security\Checks;

use Closure;
use LaraBug\Security\Middleware;
use Illuminate\Http\Middleware\TrustProxies;

/**
 * Whether every proxy is trusted, which lets any client set its own IP
 * address, scheme and host through the X-Forwarded headers.
 *
 * Read the way TrustProxies reads it on Laravel 11 to 13: what
 * `->trustProxies(at: ...)` in bootstrap/app.php set, else the `$proxies` of an
 * application's own subclass, else `trustedproxy.proxies`, else everything on
 * Laravel Cloud. The addresses themselves are never sent.
 */
class TrustedProxiesCheck implements PostureCheck
{
    public function __construct(protected readonly Middleware $middleware)
    {
    }

    /**
     * @return array{configured: bool, trusts_all: bool, source: ?string}
     */
    public function collect(): array
    {
        $class = $this->middleware->globalInstanceOf(TrustProxies::class);

        // Not in the global stack, so no proxy is trusted, whatever is set.
        if ($class === null) {
            return $this->result(null, null);
        }

        $proxies = $this->proxiesOf(app($class));

        if (! blank($proxies)) {
            return $this->result($proxies, 'middleware');
        }

        $proxies = config('trustedproxy.proxies');

        if (! blank($proxies)) {
            return $this->result($proxies, 'config');
        }

        if (function_exists('laravel_cloud') && laravel_cloud()) {
            return $this->result('*', 'laravel_cloud');
        }

        return $this->result(null, null);
    }

    /**
     * @return array{configured: bool, trusts_all: bool, source: ?string}
     */
    protected function result(mixed $proxies, ?string $source): array
    {
        return [
            'configured' => $source !== null,
            'trusts_all' => $proxies !== null && $this->trustsAll($proxies),
            'source' => $source,
        ];
    }

    /**
     * `*` and `**` trust the calling address, whoever it is. So does a list
     * naming `REMOTE_ADDR`, or one that covers every address.
     */
    protected function trustsAll(mixed $proxies): bool
    {
        if ($proxies === '*' || $proxies === '**') {
            return true;
        }

        $proxies = is_string($proxies) ? array_map(trim(...), explode(',', $proxies)) : (array) $proxies;

        foreach ($proxies as $proxy) {
            if (in_array($proxy, ['REMOTE_ADDR', '0.0.0.0/0', '::/0'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the middleware itself trusts. `proxies()` is protected, and it is
     * the one place that knows both the static setting and the property.
     */
    protected function proxiesOf(TrustProxies $middleware): mixed
    {
        return Closure::bind(fn () => $this->proxies(), $middleware, $middleware::class)();
    }
}
