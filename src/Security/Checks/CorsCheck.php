<?php

namespace LaraBug\Security\Checks;

use Illuminate\Http\Request;
use Fruitcake\Cors\CorsService;
use LaraBug\Security\Middleware;
use Illuminate\Http\Middleware\HandleCors;

/**
 * Whether any website may read this application's responses. With
 * `supports_credentials` on as well, the CORS middleware answers every
 * origin with that origin and allows credentials, so any site a logged in
 * user visits can act as them.
 *
 * The configured origins are never sent. The question put to them is
 * whether an origin nobody would list is allowed, asked of the same
 * CorsService the middleware uses, so a pattern such as `#.*#` or an origin
 * like `https://*` counts the same as `*`.
 */
class CorsCheck implements PostureCheck
{
    public function __construct(protected readonly Middleware $middleware)
    {
    }

    /**
     * @return array{applies: bool, allows_any_origin: bool, supports_credentials: bool}
     */
    public function collect(): array
    {
        $config = (array) config('cors', []);

        return [
            'applies' => $this->middleware->globalInstanceOf(HandleCors::class) !== null
                && (array) ($config['paths'] ?? []) !== [],
            'allows_any_origin' => $this->allowsAnyOrigin($config),
            'supports_credentials' => (bool) ($config['supports_credentials'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function allowsAnyOrigin(array $config): bool
    {
        if (in_array('*', (array) ($config['allowed_origins'] ?? []), true)) {
            return true;
        }

        if (! class_exists(CorsService::class)) {
            return false;
        }

        $request = Request::create('/');
        $request->headers->set('Origin', 'https://larabug-posture-check.invalid');

        return (new CorsService($config))->isOriginAllowed($request);
    }
}
