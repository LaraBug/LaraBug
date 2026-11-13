<?php

namespace LaraBug;

use LaraBug\Http\Client;
use LaraBug\Fakes\LaraBugFake;

/**
 * @method static void context(array $context)
 * @method static void clearContext()
 * @method static void assertSent(mixed $throwable, ?callable $callback = null)
 * @method static void assertRequestsSent(int $count)
 * @method static void assertNotSent(mixed $throwable, ?callable $callback = null)
 * @method static void assertNothingSent()
 */
class Facade extends \Illuminate\Support\Facades\Facade
{
    /**
     * Replace the bound instance with a fake.
     */
    public static function fake(): void
    {
        static::swap(new LaraBugFake(new Client('login_key', 'project_key')));
    }

    protected static function getFacadeAccessor(): string
    {
        return 'larabug';
    }
}
