<?php

namespace LaraBug\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LaraBug\Support\SdkVersion;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Container\Container;
use LaraBug\Http\Client as LaraBugClient;
use LaraBug\Tests\Mocks\InstalledVersions;

class SdkVersionHeaderTest extends TestCase
{
    /** @var string[] */
    protected array $temporaryFiles = [];

    public function setUp(): void
    {
        parent::setUp();

        SdkVersion::flush();
    }

    public function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        SdkVersion::flush();

        parent::tearDown();
    }

    /** @test */
    public function it_sends_the_sdk_header_next_to_the_user_agent()
    {
        $history = [];

        $handler = HandlerStack::create(new MockHandler([new Response(200, [], '{}')]));
        $handler->push(Middleware::history($history));

        $client = new LaraBugClient('login_key', 'project_key', new Client(['handler' => $handler]));

        $client->report(['exception' => 'Something broke']);

        $this->assertCount(1, $history);

        $request = $history[0]['request'];

        $this->assertSame(SdkVersion::header(), $request->getHeaderLine('X-LaraBug-Sdk'));
        $this->assertSame('LaraBug-Package', $request->getHeaderLine('User-Agent'));
    }

    /** @test */
    public function the_header_names_the_sdk_php_and_laravel_versions_as_product_tokens()
    {
        $header = SdkVersion::header();

        $this->assertSame(1, preg_match('~^larabug-php/\S+ php/\S+ laravel/\S+$~', $header), $header);

        $this->assertSame(
            'larabug-php/'.SdkVersion::packageVersion().' php/'.PHP_VERSION.' laravel/'.$this->app->version(),
            $header
        );
    }

    /** @test */
    public function it_computes_the_header_once_per_process()
    {
        $header = SdkVersion::header();

        $container = Container::getInstance();

        Container::setInstance(new Container());

        try {
            $this->assertSame('unknown', SdkVersion::laravelVersion());
            $this->assertSame($header, SdkVersion::header());
        } finally {
            Container::setInstance($container);
        }
    }

    /** @test */
    public function it_reads_the_version_from_composer_2_installed_versions()
    {
        $this->assertSame('3.8.4', SdkVersion::packageVersion(InstalledVersions::class, '/nonexistent/installed.json'));
    }

    /** @test */
    public function it_reads_the_version_the_running_composer_installed()
    {
        if (! class_exists(\Composer\InstalledVersions::class)) {
            $this->markTestSkipped('Composer 2 is not installed.');
        }

        $this->assertSame(
            \Composer\InstalledVersions::getPrettyVersion('larabug/larabug'),
            SdkVersion::packageVersion()
        );
    }

    /** @test */
    public function it_falls_back_to_a_composer_1_installed_json()
    {
        $path = $this->installedJson([
            ['name' => 'laravel/framework', 'version' => 'v8.83.27'],
            ['name' => 'larabug/larabug', 'version' => '3.8.4'],
        ]);

        $this->assertSame('3.8.4', SdkVersion::packageVersion('Composer\MissingInstalledVersions', $path));
    }

    /** @test */
    public function it_falls_back_to_a_composer_2_installed_json()
    {
        $path = $this->installedJson([
            'packages' => [
                ['name' => 'laravel/framework', 'version' => 'v10.48.0'],
                ['name' => 'larabug/larabug', 'version' => '3.8.4'],
            ],
            'dev' => true,
            'dev-package-names' => [],
        ]);

        $this->assertSame('3.8.4', SdkVersion::packageVersion('Composer\MissingInstalledVersions', $path));
    }

    /** @test */
    public function it_falls_back_to_installed_json_when_installed_versions_does_not_know_the_package()
    {
        $path = $this->installedJson([
            ['name' => 'larabug/larabug', 'version' => '3.8.4'],
        ]);

        $this->assertSame('3.8.4', SdkVersion::packageVersion(EmptyInstalledVersions::class, $path));
    }

    /** @test */
    public function it_reports_unknown_without_an_error_when_composer_has_no_version()
    {
        $this->assertSame('unknown', SdkVersion::packageVersion('Composer\MissingInstalledVersions', '/nonexistent/installed.json'));

        $this->assertSame('unknown', SdkVersion::packageVersion('Composer\MissingInstalledVersions', $this->installedFile('{not json')));

        $this->assertSame('unknown', SdkVersion::packageVersion('Composer\MissingInstalledVersions', $this->installedJson([
            ['name' => 'laravel/framework', 'version' => 'v8.83.27'],
        ])));

        $this->assertSame('unknown', SdkVersion::packageVersion('Composer\MissingInstalledVersions', $this->installedJson([
            'packages' => 'garbage',
        ])));
    }

    protected function installedJson(array $contents): string
    {
        return $this->installedFile(json_encode($contents));
    }

    protected function installedFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'larabug-installed-');

        file_put_contents($path, $contents);

        $this->temporaryFiles[] = $path;

        return $path;
    }
}

class EmptyInstalledVersions
{
    public static function isInstalled(string $packageName): bool
    {
        return false;
    }
}
