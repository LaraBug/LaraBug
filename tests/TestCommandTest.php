<?php

declare(strict_types=1);

use LaraBug\LaraBug;
use LaraBug\Commands\TestCommand;

use function Pest\Laravel\artisan;

use LaraBug\Tests\Mocks\LaraBugClient;

use function PHPUnit\Framework\assertEquals;

it('detects if the login key is set', function () {
    config(['larabug.login_key' => '']);

    artisan(TestCommand::class)
        ->expectsOutput('✗ [LaraBug] Could not find your login key, set this in your .env')
        ->assertSuccessful();

    config(['larabug.login_key' => 'test']);

    artisan(TestCommand::class)
        ->expectsOutput('✓ [Larabug] Found login key')
        ->assertSuccessful();
});

it('detects if the project key is set', function () {
    config(['larabug.project_key' => '']);

    artisan(TestCommand::class)
        ->expectsOutput('✗ [LaraBug] Could not find your project key, set this in your .env')
        ->assertSuccessful();

    config(['larabug.project_key' => 'test']);

    artisan(TestCommand::class)
        ->expectsOutput('✓ [Larabug] Found project key')
        ->assertSuccessful();
});

it('detects that its running in the correct environment', function () {
    config([
        'app.env' => 'production',
        'larabug.environments' =>[]
    ]);

    artisan(TestCommand::class)
        ->expectsOutput('✗ [LaraBug] Environment (production) not allowed to send errors to LaraBug, set this in your config')
        ->assertSuccessful();

    config([
        'larabug.environments' => ['production'],
    ]);

    artisan(TestCommand::class)
        ->expectsOutput('✓ [Larabug] Correct environment found (' . config('app.env') . ')')
        ->assertSuccessful();
});

it('detects that it fails to send to larabug', function () {
    artisan(TestCommand::class)
        ->expectsOutput('✗ [LaraBug] Failed to send exception to LaraBug')
        ->assertSuccessful();

    config(['larabug.environments' => ['testing']]);

    $this->app['larabug'] = new LaraBug($this->client = new LaraBugClient(
        'login_key',
        'project_key'
    ));

    artisan(TestCommand::class)
        ->expectsOutput('✓ [LaraBug] Sent exception to LaraBug with ID: '.LaraBugClient::RESPONSE_ID)
        ->assertSuccessful();

    assertEquals(LaraBugClient::RESPONSE_ID, $this->app['larabug']->getLastExceptionId());
});
