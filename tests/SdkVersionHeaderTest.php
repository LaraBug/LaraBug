<?php

namespace LaraBug\Tests;

use Closure;
use Exception;
use LaraBug\LaraBug;
use LaraBug\Http\Client;
use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LaraBug\Http\RetryBuffer;
use Composer\InstalledVersions;
use LaraBug\Support\SdkVersion;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Client as GuzzleClient;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use PHPUnit\Framework\Attributes\DataProvider;

class SdkVersionHeaderTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface}> */
    protected array $sent = [];

    public function setUp(): void
    {
        parent::setUp();

        config([
            'larabug.environments' => ['testing'],
            'larabug.server' => 'https://larabug.test/api/log',
        ]);
    }

    #[Test]
    public function the_header_names_the_sdk_php_and_laravel_as_product_tokens()
    {
        $this->assertMatchesRegularExpression(
            '~^larabug-php/\S+ php/\S+ laravel/\S+$~',
            SdkVersion::header()
        );

        $this->assertSame(
            'larabug-php/'.InstalledVersions::getPrettyVersion('larabug/larabug').' php/'.PHP_VERSION.' laravel/'.app()->version(),
            SdkVersion::header()
        );
    }

    #[Test]
    public function the_sdk_version_comes_from_composer()
    {
        $this->assertSame(InstalledVersions::getPrettyVersion('larabug/larabug'), SdkVersion::version());
        $this->assertNotSame('unknown', SdkVersion::version());
    }

    #[Test]
    public function a_package_composer_does_not_list_is_unknown_without_an_error()
    {
        $this->assertSame('unknown', SdkVersion::installedVersion('larabug/not-installed'));
    }

    /**
     * @param  Closure(Client): mixed  $send
     */
    #[Test]
    #[DataProvider('everyKindOfSend')]
    public function every_kind_of_send_carries_the_header(Closure $send)
    {
        $send($this->client());

        $this->assertCount(1, $this->sent);
        $this->assertSentWithTheHeader($this->sent[0]['request']);
    }

    /**
     * @return array<string, array{Closure(Client): mixed}>
     */
    public static function everyKindOfSend(): array
    {
        return [
            'exception' => [fn (Client $client) => (new LaraBug($client))->handle(new Exception('Versioned'))],
            'requests' => [fn (Client $client) => $client->reportRequests([['method' => 'GET']])],
            'commands' => [fn (Client $client) => $client->reportCommands([['command' => 'inspire']])],
            'scheduled tasks' => [fn (Client $client) => $client->reportScheduledTasks([['command' => 'inspire']])],
            'queue depth' => [fn (Client $client) => $client->reportQueueDepth([['queue' => 'default', 'size' => 3]])],
            'logs' => [fn (Client $client) => $client->report(['type' => 'logs_batch', 'logs' => []])],
            'jobs' => [fn (Client $client) => $client->report(['type' => 'jobs_batch', 'jobs' => []])],
            'cve scan' => [fn (Client $client) => $client->report(['type' => 'cve_scan', 'composer_lock' => []])],
        ];
    }

    #[Test]
    public function the_cve_scan_command_sends_the_header()
    {
        $lockPath = tempnam(sys_get_temp_dir(), 'larabug-sdk-version-lock');
        file_put_contents($lockPath, json_encode(['packages' => [['name' => 'monolog/monolog', 'version' => '3.5.0']]]));

        config([
            'larabug.cve.enabled' => true,
            'larabug.cve.lock_path' => $lockPath,
            'larabug.cve.npm' => false,
        ]);

        $this->app->instance(Client::class, $this->client(new Response(202, [], '{"queued":true}')));

        $this->artisan('larabug:scan')->assertExitCode(0);

        unlink($lockPath);

        $this->assertCount(1, $this->sent);
        $this->assertSame('cve_scan', json_decode((string) $this->sent[0]['request']->getBody(), true)['type']);
        $this->assertSentWithTheHeader($this->sent[0]['request']);
    }

    #[Test]
    public function a_flushed_event_carries_the_version_that_sent_it()
    {
        mkdir($this->retryBufferPath, 0775, true);

        file_put_contents(
            sprintf('%s/%010d.000000-%s.json', $this->retryBufferPath, time() - 60, bin2hex(random_bytes(4))),
            json_encode(['type' => 'logs_batch', 'logs' => [['message' => 'Kept by an older SDK']]])
        );

        $result = RetryBuffer::fromConfig()->flush($this->client());

        $this->assertSame(1, $result->sent);
        $this->assertSentWithTheHeader($this->sent[0]['request']);
    }

    #[Test]
    public function the_buffer_keeps_the_body_and_not_the_header()
    {
        $this->client(new Response(503))->report(['type' => 'logs_batch', 'logs' => []]);

        $kept = glob("{$this->retryBufferPath}/*.json");

        $this->assertCount(1, $kept);
        $this->assertStringNotContainsString('larabug-php/', (string) file_get_contents($kept[0]));
    }

    #[Test]
    public function the_user_agent_still_starts_with_the_package_name()
    {
        $this->client()->report(['exception' => 'Versioned']);

        $this->assertSame(
            'LaraBug-Package/'.SdkVersion::version(),
            $this->sent[0]['request']->getHeaderLine('User-Agent')
        );
    }

    protected function assertSentWithTheHeader(RequestInterface $request): void
    {
        $this->assertSame(SdkVersion::header(), $request->getHeaderLine('X-LaraBug-Sdk'));
    }

    protected function client(?Response $answer = null): Client
    {
        $stack = HandlerStack::create(new MockHandler([$answer ?? new Response(200, [], '{"id":"test"}')]));
        $stack->push(Middleware::history($this->sent));

        return new Client('login-key', 'project-key', new GuzzleClient(['handler' => $stack]));
    }
}
