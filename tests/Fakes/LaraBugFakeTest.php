<?php

declare(strict_types=1);

use LaraBug\Facade;

use function Pest\Laravel\get;

use Illuminate\Support\Facades\Route;

use function PHPUnit\Framework\assertSame;

use Illuminate\Database\Eloquent\ModelNotFoundException;

beforeEach(function () {
    Facade::fake();

    config([
        'logging.channels.larabug' => ['driver' => 'larabug'],
        'logging.default' => 'larabug',
        'larabug.environments' => 'testing',
    ]);
});

it('will sent exception to larabug if exception is thrown', function () {
    Route::get('exception', function () {
        throw new \Exception('Exception');
    });

    get('exception');

    Facade::assertSent(\Exception::class);

    Facade::assertSent(\Exception::class, function (\Throwable $throwable) {
        assertSame('Exception', $throwable->getMessage());

        return true;
    });

    Facade::assertNotSent(ModelNotFoundException::class);
});

it('will sent nothing to larabug if no exceptions thrown', function () {
    Facade::fake();

    Route::get('nothing', function () {
            //
    });

    get('nothing');

    Facade::assertNothingSent();
});
