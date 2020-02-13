<?php

namespace LaraBug\Tests;

use LaraBug\LaraBug;
use LaraBug\Tests\Mocks\LaraBugClient;

class TestCommandTest extends TestCase
{
    /** @test */
    public function it_detects_if_the_login_key_is_set()
    {
        $this->app['config']['larabug.login_key'] = '';

        $this->artisan('larabug:test')
            ->expectsOutput('✗ [LaraBug] Could not find your login key, set this in your .env')
            ->assertExitCode(0);

        $this->app['config']['larabug.login_key'] = 'test';

        $this->artisan('larabug:test')
            ->expectsOutput('✓ [Larabug] Found login key')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_detects_if_the_project_key_is_set()
    {
        $this->app['config']['larabug.project_key'] = '';

        $this->artisan('larabug:test')
            ->expectsOutput('✗ [LaraBug] Could not find your project key, set this in your .env')
            ->assertExitCode(0);

        $this->app['config']['larabug.project_key'] = 'test';

        $this->artisan('larabug:test')
            ->expectsOutput('✓ [Larabug] Found project key')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_detects_that_its_running_in_the_correct_environment()
    {
        $this->app['config']['app.env'] = 'production';
        $this->app['config']['larabug.environments'] = [];

        $this->artisan('larabug:test')
            ->expectsOutput('✗ [LaraBug] Environment not allowed to send errors to LaraBug, set this in your config')
            ->assertExitCode(0);

        $this->app['config']['larabug.environments'] = ['production'];

        $this->artisan('larabug:test')
            ->expectsOutput('✓ [Larabug] Correct environment found')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_detects_that_it_fails_to_send_to_larabug()
    {
        $this->artisan('larabug:test')
            ->expectsOutput('✗ [LaraBug] Failed to send exception to LaraBug')
            ->assertExitCode(0);

        $this->app['config']['larabug.environments'] = [
            'testing'
        ];
        $this->app['larabug'] = new LaraBug($this->client = new LaraBugClient(
            'login_key', 'project_key'
        ));

        $this->artisan('larabug:test')
            ->expectsOutput('✓ [LaraBug] Sent exception to LaraBug with ID: ' . LaraBugClient::RESPONSE_ID)
            ->assertExitCode(0);
    }
}
