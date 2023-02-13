<?php

declare(strict_types=1);

use LaraBug\Facade;

use function Pest\Laravel\get;

use Illuminate\Support\Facades\Log;

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Facade::fake();

    config([
        'logging.channels.larabug' => ['driver' => 'larabug'],
        'logging.default' => 'larabug',
        'larabug.environments' => 'testing',
    ]);
});

it('will not send log information to larabug', function () {
    Route::get('log-information-via-route/{type}', function (string $type) {
        Log::{$type}('log');
    });

    get('log-information-via-route/debug');
    get('log-information-via-route/info');
    get('log-information-via-route/notice');
    get('log-information-via-route/warning');
    get('log-information-via-route/error');
    get('log-information-via-route/critical');
    get('log-information-via-route/alert');
    get('log-information-via-route/emergency');

    Facade::assertRequestsSent(0);
});

it('will only send throwables to larabug', function () {
    Route::get('throwables-via-route', function () {
        throw new \Exception('exception-via-route');
    });

    get('throwables-via-route');

    Facade::assertRequestsSent(1);
});
