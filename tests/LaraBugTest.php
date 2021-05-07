<?php

namespace LaraBug\Tests;

use Exception;
use Carbon\Carbon;
use LaraBug\LaraBug;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Handler\MockHandler;
use LaraBug\Tests\Mocks\LaraBugClient;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LaraBugTest extends TestCase
{
    /** @var LaraBug */
    protected $laraBug;

    /** @var Mocks\LaraBugClient */
    protected $client;

    public function setUp(): void
    {
        parent::setUp();

        $this->laraBug = new LaraBug($this->client = new LaraBugClient(
            'login_key',
            'project_key'
        ));
    }

    /** @test */
    public function is_will_not_crash_if_larabug_returns_error_bad_response_exception()
    {
        $this->laraBug = new LaraBug($this->client = new \LaraBug\Http\Client(
            'login_key',
            'project_key'
        ));

        //
        $this->app['config']['larabug.environments'] = ['testing'];

        $this->client->setGuzzleHttpClient(new Client([
            'handler' => MockHandler::createWithMiddleware([
                new Response(500, [], '{}'),
            ]),
        ]));

        $this->assertInstanceOf(get_class(new \stdClass()), $this->laraBug->handle(new Exception('is_will_not_crash_if_larabug_returns_error_bad_response_exception')));
    }

    /** @test */
    public function is_will_not_crash_if_larabug_returns_normal_exception()
    {
        $this->laraBug = new LaraBug($this->client = new \LaraBug\Http\Client(
            'login_key',
            'project_key'
        ));

        //
        $this->app['config']['larabug.environments'] = ['testing'];

        $this->client->setGuzzleHttpClient(new Client([
            'handler' => MockHandler::createWithMiddleware([
                new \Exception(),
            ]),
        ]));

        $this->assertFalse($this->laraBug->handle(new Exception('is_will_not_crash_if_larabug_returns_normal_exception')));
    }

    /** @test */
    public function it_can_skip_exceptions_based_on_class()
    {
        $this->app['config']['larabug.except'] = [];

        $this->assertFalse($this->laraBug->isSkipException(NotFoundHttpException::class));

        $this->app['config']['larabug.except'] = [
            NotFoundHttpException::class,
        ];

        $this->assertTrue($this->laraBug->isSkipException(NotFoundHttpException::class));
    }

    /** @test */
    public function it_can_skip_exceptions_based_on_environment()
    {
        $this->app['config']['larabug.environments'] = [];

        $this->assertTrue($this->laraBug->isSkipEnvironment());

        $this->app['config']['larabug.environments'] = ['production'];

        $this->assertTrue($this->laraBug->isSkipEnvironment());

        $this->app['config']['larabug.environments'] = ['testing'];

        $this->assertFalse($this->laraBug->isSkipEnvironment());
    }

    /** @test */
    public function it_will_return_false_for_sleeping_cache_exception_if_disabled()
    {
        $this->app['config']['larabug.sleep'] = 0;

        $this->assertFalse($this->laraBug->isSleepingException([]));
    }

    /** @test */
    public function it_can_check_if_is_a_sleeping_cache_exception()
    {
        $data = ['host' => 'localhost', 'method' => 'GET', 'exception' => 'it_can_check_if_is_a_sleeping_cache_exception', 'line' => 2, 'file' => '/tmp/Larabug/tests/LaraBugTest.php', 'class' => 'Exception'];

        Carbon::setTestNow('2019-10-12 13:30:00');

        $this->assertFalse($this->laraBug->isSleepingException($data));

        Carbon::setTestNow('2019-10-12 13:30:00');

        $this->laraBug->addExceptionToSleep($data);

        $this->assertTrue($this->laraBug->isSleepingException($data));

        Carbon::setTestNow('2019-10-12 13:31:00');

        $this->assertTrue($this->laraBug->isSleepingException($data));

        Carbon::setTestNow('2019-10-12 13:31:01');

        $this->assertFalse($this->laraBug->isSleepingException($data));
    }

    /** @test */
    public function it_can_get_formatted_exception_data()
    {
        $data = $this->laraBug->getExceptionData(new Exception(
            'it_can_get_formatted_exception_data'
        ));

        $this->assertSame('testing', $data['enviroment']);
        $this->assertSame('localhost', $data['host']);
        $this->assertSame('GET', $data['method']);
        $this->assertSame('http://localhost', $data['fullUrl']);
        $this->assertSame('it_can_get_formatted_exception_data', $data['exception']);

        $this->assertCount(12, $data);
    }

    /** @test */
    public function it_filters_the_data_based_on_the_configuration()
    {
        $this->assertContains('*password*', $this->app['config']['larabug.blacklist']);

        $data = [
            'password' => 'testing',
            'not_password' => 'testing',
            'not_password2' => [
                'password' => 'testing',
            ],
            'not_password_3' => [
                'nah' => [
                    'password' => 'testing',
                ],
            ],
            'Password' => 'testing',
        ];


        $this->assertContains('***', $this->laraBug->filterVariables($data));
//        $this->assertArrayHasKey('not_password', $this->laraBug->filterVariables($data));
//        $this->assertArrayNotHasKey('password', $this->laraBug->filterVariables($data)['not_password2']);
//        $this->assertArrayNotHasKey('password', $this->laraBug->filterVariables($data)['not_password_3']['nah']);
//        $this->assertArrayNotHasKey('Password', $this->laraBug->filterVariables($data));
    }

    /** @test */
    public function it_can_report_an_exception_to_larabug()
    {
        $this->app['config']['larabug.environments'] = ['testing'];

        $this->laraBug->handle(new Exception('it_can_report_an_exception_to_larabug'));

        $this->client->assertRequestsSent(1);
    }
}
