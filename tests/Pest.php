<?php

declare(strict_types=1);

use LaraBug\Tests\TestCase;
use Illuminate\Support\Facades\Http;

uses(TestCase::class)
    ->beforeEach(function () {
        if (version_compare(app()::VERSION, '9.0.0', '>=')) {
            Http::preventStrayRequests();
        }
    })
    ->in(__DIR__);
