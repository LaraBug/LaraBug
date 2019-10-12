<?php

namespace LaraBug\Tests;

use Carbon\Carbon;
use Exception;
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

        $this->assertFalse($this->larabug->isSkipEnvironment());

        $this->app['config']['larabug.environments'] = [
            'production'
        ];

        $this->assertFalse($this->larabug->isSkipEnvironment());

        $this->app['config']['larabug.environments'] = [
            'testing'
        ];

        $this->assertTrue($this->larabug->isSkipEnvironment());
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

        $this->assertCount(11, $data);
    }

    /** @test */
    public function it_can_report_an_exception_to_larabug()
    {
        $this->larabug->handle(new Exception('it_can_report_an_exception_to_larabug'));

        $this->client->assertRequestsSent(1);
    }
}
