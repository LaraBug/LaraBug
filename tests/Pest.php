<?php

use LaraBug\Tests\TestCase;
use Illuminate\Support\Facades\Http;

uses(TestCase::class)
    ->beforeEach(function () {
        if (version_compare(app()->version(), '9.0.0', '>=')) {
            Http::preventStrayRequests();
        }
    })
    ->in(__DIR__);
