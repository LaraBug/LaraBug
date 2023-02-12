<?php

declare(strict_types=1);


use Carbon\Carbon;
use LaraBug\LaraBug;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Handler\MockHandler;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertContains;

use LaraBug\Tests\Support\Mocks\LaraBugClient;

use function PHPUnit\Framework\assertInstanceOf;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    $this->laraBug = new LaraBug($this->client = new LaraBugClient(
        'login_key',
        'project_key'
    ));
});

it('will not crash if larabug returns error bad response exception', function () {
    $this->laraBug = new LaraBug($this->client = new \LaraBug\Http\Client(
        'login_key',
        'project_key'
    ));

    config(['larabug.environments'=>['testing']]);

    $this->client->setGuzzleHttpClient(new Client([
        'handler' => MockHandler::createWithMiddleware([
            new Response(500, [], '{}'),
        ]),
    ]));

    assertInstanceOf(
        (new \stdClass())::class,
        $this->laraBug->handle(new Exception('is_will_not_crash_if_larabug_returns_error_bad_response_exception'))
    );
});

it('will not crash if larabug returns normal exception', function () {
    $this->laraBug = new LaraBug($this->client = new \LaraBug\Http\Client(
        'login_key',
        'project_key'
    ));

    config(['larabug.environments'=>['testing']]);

    $this->client->setGuzzleHttpClient(new Client([
        'handler' => MockHandler::createWithMiddleware([
            new \Exception(),
        ]),
    ]));

    assertFalse($this->laraBug->handle(new Exception('is_will_not_crash_if_larabug_returns_normal_exception')));
});

it('can skip exceptions based on class', function () {
    config(['larabug.except'=>['']]);

    assertFalse($this->laraBug->isSkipException(NotFoundHttpException::class));

    config(['larabug.except'=>[NotFoundHttpException::class]]);

    assertTrue($this->laraBug->isSkipException(NotFoundHttpException::class));
});

it('can skip exceptions based on environment', function () {
    config(['larabug.environments'=>['']]);

    assertTrue($this->laraBug->isSkipEnvironment());

    config(['larabug.environments'=>['production']]);

    assertTrue($this->laraBug->isSkipEnvironment());

    config(['larabug.environments'=>['testing']]);

    assertFalse($this->laraBug->isSkipEnvironment());
});

it('will return false for sleeping cache exception if disabled', function () {
    config(['larabug.sleep' => 0]);

    assertFalse($this->laraBug->isSleepingException([]));
});

it('can check if is a sleeping cache exception', function () {
    $data = [
        'host' => 'localhost',
        'method' => 'GET',
        'exception' => 'it_can_check_if_is_a_sleeping_cache_exception',
        'line' => 2,
        'file' => '/tmp/Larabug/tests/LaraBugTest.php',
        'class' => 'Exception'
    ];

    Carbon::setTestNow('2019-10-12 13:30:00');

    assertFalse($this->laraBug->isSleepingException($data));

    Carbon::setTestNow('2019-10-12 13:30:00');

    $this->laraBug->addExceptionToSleep($data);

    assertTrue($this->laraBug->isSleepingException($data));

    Carbon::setTestNow('2019-10-12 13:31:00');

    assertTrue($this->laraBug->isSleepingException($data));

    Carbon::setTestNow('2019-10-12 13:31:01');

    assertFalse($this->laraBug->isSleepingException($data));
});

it('can get formatted exception data', function () {
    $data = $this->laraBug->getExceptionData(new Exception(
        'it_can_get_formatted_exception_data'
    ));

    assertSame('testing', $data['environment']);
    assertSame('localhost', $data['host']);
    assertSame('GET', $data['method']);
    assertSame('http://localhost', $data['fullUrl']);
    assertSame('it_can_get_formatted_exception_data', $data['exception']);

    assertCount(13, $data);
});

it('filters the data based on the configuration', function () {
    assertContains('*password*', config('larabug.blacklist'));

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


    assertContains('***', $this->laraBug->filterVariables($data));
//        $this->assertArrayHasKey('not_password', $this->laraBug->filterVariables($data));
//        $this->assertArrayNotHasKey('password', $this->laraBug->filterVariables($data)['not_password2']);
//        $this->assertArrayNotHasKey('password', $this->laraBug->filterVariables($data)['not_password_3']['nah']);
//        $this->assertArrayNotHasKey('Password', $this->laraBug->filterVariables($data));
});

it('can report an exception to larabug', function () {
    config(['larabug.environments' => ['testing']]);

    $this->laraBug->handle(new Exception('it_can_report_an_exception_to_larabug'));

    $this->client->assertRequestsSent(1);
});
