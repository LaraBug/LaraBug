<?php

declare(strict_types=1);

namespace LaraBug;

use LaraBug\Http\Client;
use LaraBug\Fakes\LaraBugFake;

/**
 * @method static void assertSent($throwable, $callback = null)
 * @method static void assertRequestsSent(int $count)
 * @method static void assertNotSent($throwable, $callback = null)
 * @method static void assertNothingSent()
 */
class Facade extends \Illuminate\Support\Facades\Facade
{
    public static function fake(): void
    {
        static::swap(new LaraBugFake(new Client('login_key', 'project_key')));
    }

    protected static function getFacadeAccessor(): string
    {
        return 'larabug';
    }
}
