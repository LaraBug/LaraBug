<?php

namespace LaraBug\Tests;

use Carbon\Carbon;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use LaraBug\LaraBug;
use LaraBug\Tests\Mocks\LaraBugClient;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LaraBugTest extends TestCase
{
    /** @var LaraBug */
    protected $larabug;

    /** @var Mocks\LaraBugClient */
    protected $client;

    public function setUp(): void
    {
        parent::setUp();

        $this->larabug = new LaraBug($this->client = new LaraBugClient(
            'login_key', 'project_key'
        ));
    }

    /** @test */
    public function is_will_not_crash_if_larabug_returns_error_500()
    {
        $this->larabug = new LaraBug($this->client = new \LaraBug\Http\Client(
            'login_key', 'project_key'
        ));

        //
        $this->app['config']['larabug.environments'] = ['testing'];

        $this->client->setGuzzleHttpClient(new Client([
            'handler' => MockHandler::createWithMiddleware([
                new Response(500, [], '{}')
            ]),
        ]));

        $this->assertInstanceOf(get_class(new \stdClass()), $this->larabug->handle(new Exception('is_will_not_crash_if_larabug_returns_error_500')));
    }

    /** @test */
    public function it_can_skip_exceptions_based_on_class()
    {
        $this->app['config']['larabug.except'] = [];

        $this->assertFalse($this->larabug->isSkipException(NotFoundHttpException::class));

        $this->app['config']['larabug.except'] = [
            NotFoundHttpException::class
        ];

        $this->assertTrue($this->larabug->isSkipException(NotFoundHttpException::class));
    }

    /** @test */
    public function it_can_skip_exceptions_based_on_environment()
    {
        $this->app['config']['larabug.environments'] = [];

        $this->assertTrue($this->larabug->isSkipEnvironment());

        $this->app['config']['larabug.environments'] = ['production'];

        $this->assertTrue($this->larabug->isSkipEnvironment());

        $this->app['config']['larabug.environments'] = ['testing'];

        $this->assertFalse($this->larabug->isSkipEnvironment());
    }

    /** @test */
    public function it_will_return_false_for_sleeping_cache_exception_if_disabled()
    {
        $this->app['config']['larabug.sleep'] = 0;

        $this->assertFalse($this->larabug->isSleepingException([]));
    }

    /** @test */
    public function it_can_check_if_is_a_sleeping_cache_exception()
    {
        Carbon::setTestNow('2019-10-12 13:30:00');

        $data = ['host' => 'localhost', 'method' => 'GET', 'exception' => 'it_can_check_if_is_a_sleeping_cache_exception', 'line' => 2, 'file' => '/tmp/Larabug/tests/LaraBugTest.php', 'class' => 'Exception'];

        $this->assertFalse($this->larabug->isSleepingException($data));

        Carbon::setTestNow('2019-10-12 13:30:01');

        $this->larabug->addExceptionToSleep($data);

        Carbon::setTestNow('2019-10-12 13:30:06');

        $this->assertTrue($this->larabug->isSleepingException($data));

        Carbon::setTestNow('2019-10-12 13:30:07');

        $this->assertFalse($this->larabug->isSleepingException($data));
    }

    /** @test */
    public function it_can_get_formatted_exception_data()
    {
        $data = $this->larabug->getExceptionData(new Exception(
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

        $this->assertContains('password', $this->app['config']['larabug.blacklist']);

        $data = [
            'password' => 'testing',
            'not_password' => 'testing',
            'not_password2' => [
                'password' => 'testing'
            ],
            'not_password_3' => [
                'nah' => [
                    'password' => 'testing'
                ]
            ],
            'Password' => 'testing'
        ];

        $this->assertArrayNotHasKey('password', $this->larabug->filterVariables($data));
        $this->assertArrayHasKey('not_password', $this->larabug->filterVariables($data));
        $this->assertArrayNotHasKey('password', $this->larabug->filterVariables($data)['not_password2']);
        $this->assertArrayNotHasKey('password', $this->larabug->filterVariables($data)['not_password_3']['nah']);
        $this->assertArrayNotHasKey('Password', $this->larabug->filterVariables($data));
    }

    /** @test */
    public function it_can_report_an_exception_to_larabug()
    {
        $this->app['config']['larabug.environments'] = ['testing'];

        $this->larabug->handle(new Exception('it_can_report_an_exception_to_larabug'));

        $this->client->assertRequestsSent(1);
    }
}
