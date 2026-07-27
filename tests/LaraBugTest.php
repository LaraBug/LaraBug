<?php

namespace LaraBug\Tests;

use Exception;
use Carbon\Carbon;
use LaraBug\LaraBug;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Handler\MockHandler;
use LaraBug\Http\Client as HttpClient;
use LaraBug\Tests\Mocks\LaraBugClient;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LaraBugTest extends TestCase
{
    protected LaraBug $laraBug;

    protected HttpClient $client;

    public function setUp(): void
    {
        parent::setUp();

        $this->laraBug = new LaraBug($this->client = new LaraBugClient(
            'login_key',
            'project_key'
        ));
    }

    #[Test]
    public function is_will_not_crash_if_larabug_returns_error_bad_response_exception()
    {
        $this->laraBug = new LaraBug($this->client = new HttpClient(
            'login_key',
            'project_key'
        ));

        $this->app['config']['larabug.environments'] = ['testing'];

        $this->client->setGuzzleHttpClient(new Client([
            'handler' => MockHandler::createWithMiddleware([
                new Response(500, [], '{}'),
            ]),
        ]));

        $this->assertInstanceOf(get_class(new \stdClass()), $this->laraBug->handle(new Exception('is_will_not_crash_if_larabug_returns_error_bad_response_exception')));
    }

    #[Test]
    public function is_will_not_crash_if_larabug_returns_normal_exception()
    {
        $this->laraBug = new LaraBug($this->client = new HttpClient(
            'login_key',
            'project_key'
        ));

        $this->app['config']['larabug.environments'] = ['testing'];

        $this->client->setGuzzleHttpClient(new Client([
            'handler' => MockHandler::createWithMiddleware([
                new \Exception(),
            ]),
        ]));

        $this->assertFalse($this->laraBug->handle(new Exception('is_will_not_crash_if_larabug_returns_normal_exception')));
    }

    #[Test]
    public function it_can_skip_exceptions_based_on_class()
    {
        $this->app['config']['larabug.except'] = [];

        $this->assertFalse($this->laraBug->isSkipException(NotFoundHttpException::class));

        $this->app['config']['larabug.except'] = [
            NotFoundHttpException::class,
        ];

        $this->assertTrue($this->laraBug->isSkipException(NotFoundHttpException::class));
    }

    #[Test]
    public function it_can_skip_exceptions_based_on_environment()
    {
        $this->app['config']['larabug.environments'] = [];

        $this->assertTrue($this->laraBug->isSkipEnvironment());

        $this->app['config']['larabug.environments'] = ['production'];

        $this->assertTrue($this->laraBug->isSkipEnvironment());

        $this->app['config']['larabug.environments'] = ['testing'];

        $this->assertFalse($this->laraBug->isSkipEnvironment());
    }

    #[Test]
    public function it_will_return_false_for_sleeping_cache_exception_if_disabled()
    {
        $this->app['config']['larabug.sleep'] = 0;

        $this->assertFalse($this->laraBug->isSleepingException([]));
    }

    #[Test]
    public function it_can_check_if_is_a_sleeping_cache_exception()
    {
        $data = ['host' => 'localhost', 'method' => 'GET', 'exception' => 'it_can_check_if_is_a_sleeping_cache_exception', 'line' => 2, 'file' => '/tmp/Larabug/tests/LaraBugTest.php', 'class' => 'Exception'];

        Carbon::setTestNow('2019-10-12 13:30:00');

        $this->assertFalse($this->laraBug->isSleepingException($data));

        Carbon::setTestNow('2019-10-12 13:30:00');

        $this->laraBug->addExceptionToSleep($data);

        $this->assertTrue($this->laraBug->isSleepingException($data));

        Carbon::setTestNow('2019-10-12 13:30:59');

        $this->assertTrue($this->laraBug->isSleepingException($data));

        Carbon::setTestNow('2019-10-12 13:31:01');

        $this->assertFalse($this->laraBug->isSleepingException($data));
    }

    #[Test]
    public function it_can_get_formatted_exception_data()
    {
        $data = $this->laraBug->getExceptionData(new Exception(
            'it_can_get_formatted_exception_data'
        ));

        $this->assertSame('testing', $data['environment']);
        $this->assertSame('localhost', $data['host']);
        $this->assertSame('GET', $data['method']);
        $this->assertSame('http://localhost', $data['fullUrl']);
        $this->assertSame('it_can_get_formatted_exception_data', $data['exception']);

        // trace_id lets the server join the exception to the request that failed.
        $this->assertArrayHasKey('trace_id', $data);
        $this->assertNotSame('', $data['trace_id']);

        $this->assertCount(15, $data);
    }

    #[Test]
    public function it_collects_a_window_of_source_for_each_frame()
    {
        $data = $this->laraBug->getExceptionData(new Exception(
            'it_collects_a_window_of_source_for_each_frame'
        ));

        $this->assertIsArray($data['frames']);
        $this->assertNotEmpty($data['frames']);

        $this->assertSame(__FILE__, $data['frames'][0]['file']);
        $this->assertNotEmpty($data['frames'][0]['code']);

        // Code rows must stay a list so they encode as a JSON array, not a keyed object.
        $row = $data['frames'][0]['code'][0];
        $this->assertArrayHasKey('line_number', $row);
        $this->assertArrayHasKey('line', $row);
        $this->assertSame(array_values($data['frames'][0]['code']), $data['frames'][0]['code']);
    }

    #[Test]
    public function it_caps_how_many_frames_carry_source()
    {
        $this->app['config']['larabug.max_code_frames'] = 1;

        $data = $this->laraBug->getExceptionData(new Exception(
            'it_caps_how_many_frames_carry_source'
        ));

        $framesWithCode = count(array_filter($data['frames'], function ($frame) {
            return ! empty($frame['code']);
        }));

        $this->assertSame(1, $framesWithCode);

        $this->assertGreaterThan(1, count($data['frames']));
        $this->assertNotEmpty($data['frames'][1]['file']);
    }

    #[Test]
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
    }

    #[Test]
    public function it_can_report_an_exception_to_larabug()
    {
        $this->app['config']['larabug.environments'] = ['testing'];

        $this->laraBug->handle(new Exception('it_can_report_an_exception_to_larabug'));

        $this->client->assertRequestsSent(1);
    }
}
