<?php

declare(strict_types=1);

use LaraBug\Tests\TestCase;
use Illuminate\Support\Facades\Http;

uses(TestCase::class)
    ->beforeEach(fn () => Http::preventStrayRequests())
    ->in(__DIR__);
