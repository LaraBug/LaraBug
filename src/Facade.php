<?php

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
    /**
     * Replace the bound instance with a fake.
     *
     * @return void
     */
    public static function fake()
    {
        static::swap(new LaraBugFake(new Client('login_key', 'project_key')));
    }

    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'larabug';
    }
}
