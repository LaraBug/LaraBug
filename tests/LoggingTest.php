<?php

namespace LaraBug\Tests;

use Illuminate\Support\Facades\Route;
use LaraBug\LaraBug;
use LaraBug\Tests\Mocks\LaraBugClient;

class LoggingTest extends TestCase
{
    /** @var Mocks\LaraBugClient */
    protected $client;

    /**
     * Setup the test environment.
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->app['config']['logging.channels.larabug'] = [
            'driver' => 'larabug',
        ];

        $this->app['config']['logging.default'] = 'larabug';
        $this->app['config']['larabug.login_key'] = 'login_key';
        $this->app['config']['larabug.project_key'] = 'project_key';

        $this->client = new LaraBugClient('login_key', 'project_key');

        $this->app->singleton('larabug', function () {
            return new LaraBug($this->client);
        });
    }

    /** @test */
    public function it_will_not_send_log_information_to_larabug()
    {
        Route::get('/log-information-via-route/{type}', function ($type) {
            \Illuminate\Support\Facades\Log::{$type}('log');
        });

        $this->get('/log-information-via-route/debug');
        $this->get('/log-information-via-route/info');
        $this->get('/log-information-via-route/notice');
        $this->get('/log-information-via-route/warning');
        $this->get('/log-information-via-route/error');
        $this->get('/log-information-via-route/critical');
        $this->get('/log-information-via-route/alert');
        $this->get('/log-information-via-route/emergency');

        $this->client->assertRequestsSent(0);
    }

    /** @test */
    public function it_will_only_send_throwables_to_larabug()
    {
        Route::get('/throwables-via-route', function () {
            throw new \Exception('exception-via-route');
        });

        $this->get('/throwables-via-route');

        $this->client->assertRequestsSent(1);
    }
}