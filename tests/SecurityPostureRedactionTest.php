<?php

namespace LaraBug\Tests;

use LaraBug\Http\Client;
use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;
use Laravel\Horizon\Horizon;
use GuzzleHttp\Psr7\Response;
use Laravel\Telescope\Telescope;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Support\Facades\Gate;
use GuzzleHttp\Client as GuzzleClient;
use PHPUnit\Framework\Attributes\Test;
use LaraBug\Security\InstalledPackages;
use Illuminate\Http\Middleware\TrustProxies;
use LaraBug\Tests\Mocks\FakeInstalledPackages;

/**
 * The posture snapshot is booleans, names from fixed sets and versions. Every
 * value it is built from is set to a secret here, with every tool loaded and
 * every check failing, and none of them may reach the wire.
 */
class SecurityPostureRedactionTest extends TestCase
{
    /** @var array<int, string> */
    protected array $secrets = [
        'base64:c2VjcmV0LWFwcC1rZXktdmFsdWU=',
        'secret-database-password',
        'secret_session_cookie',
        'secret-cookie-domain.example',
        'secret-telescope-path',
        'secret-horizon-path',
        'secret-pulse-path',
        'https://secret-partner.example',
        'secret-pattern',
        '10.13.37.42',
        '192.0.2.77',
        'secret-debugbar-storage',
        'secret-login-key',
        'sk_live_secret_stripe_key',
        'secret-viewer@example.com',
        'secret-release-name',
    ];

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    protected array $sent = [];

    public function setUp(): void
    {
        parent::setUp();

        require_once __DIR__.'/Fixtures/SecurityTools.php';

        $this->app['env'] = 'production';

        config([
            'app.env' => 'production',
            'app.debug' => true,
            'app.key' => 'base64:c2VjcmV0LWFwcC1rZXktdmFsdWU=',
            'larabug.environments' => ['production'],
            'larabug.security.posture.enabled' => true,
            'database.connections.mysql.password' => 'secret-database-password',
            'services.stripe.secret' => 'sk_live_secret_stripe_key',
            'session.cookie' => 'secret_session_cookie',
            'session.domain' => 'secret-cookie-domain.example',
            'session.secure' => false,
            'session.http_only' => false,
            'session.same_site' => 'secret-same-site-typo',
            'cors' => [
                'paths' => ['api/*'],
                'allowed_origins' => ['https://secret-partner.example', '*'],
                'allowed_origins_patterns' => ['#^https://secret-pattern\.example$#'],
                'supports_credentials' => true,
            ],
            'trustedproxy.proxies' => '192.0.2.77',
            'telescope.enabled' => true,
            'telescope.path' => 'secret-telescope-path',
            'telescope.middleware' => ['web'],
            'horizon.path' => 'secret-horizon-path',
            'pulse.enabled' => true,
            'pulse.path' => 'secret-pulse-path',
            'pulse.middleware' => ['web', 'Laravel\Pulse\Http\Middleware\Authorize'],
            'debugbar.enabled' => true,
            'debugbar.storage.open' => true,
            'debugbar.storage.path' => '/var/secret-debugbar-storage',
            // Released under a name that is itself a secret, to prove the one
            // field that does carry a configured value is only ever this one.
            'larabug.project_version' => 'secret-release-name',
        ]);

        TrustProxies::at(['10.13.37.42', 'REMOTE_ADDR']);

        Telescope::auth(fn () => true);
        Horizon::auth(fn () => true);
        Gate::define('viewPulse', fn ($user = null) => true);
        Gate::define('viewTelescope', fn ($user) => $user->email === 'secret-viewer@example.com');

        $this->app->instance(InstalledPackages::class, new FakeInstalledPackages([
            'laravel/telescope' => '5.10.0',
            'laravel/horizon' => '5.40.0',
            'laravel/pulse' => '1.4.0',
            'barryvdh/laravel-debugbar' => '3.16.0',
            'spatie/laravel-ignition' => '2.9.1',
        ], devInstalled: true));

        foreach ([
            \Laravel\Telescope\TelescopeServiceProvider::class,
            \Laravel\Horizon\HorizonServiceProvider::class,
            \Laravel\Pulse\PulseServiceProvider::class,
            \Barryvdh\Debugbar\ServiceProvider::class,
            \Spatie\LaravelIgnition\IgnitionServiceProvider::class,
        ] as $provider) {
            $this->app->register($provider);
        }

        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{}')]));
        $stack->push(Middleware::history($this->sent));

        $this->app->instance(Client::class, new Client('secret-login-key', 'project-key', new GuzzleClient(['handler' => $stack])));
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();

        Telescope::$authUsing = null;
        Horizon::$authUsing = null;

        parent::tearDown();
    }

    #[Test]
    public function every_check_fails_so_every_value_was_read()
    {
        $posture = $this->sentPosture();
        $checks = $posture['checks'];

        $this->assertTrue($checks['debug_mode']['enabled']);
        $this->assertSame(['secure' => false, 'http_only' => false, 'same_site' => 'invalid'], $checks['session_cookie']);
        $this->assertTrue($checks['trusted_proxies']['trusts_all']);
        $this->assertTrue($checks['cors']['allows_any_origin']);
        $this->assertTrue($checks['cors']['supports_credentials']);
        $this->assertTrue($checks['dev_packages']['composer_dev_installed']);
        $this->assertSame([['package' => 'spatie/laravel-ignition', 'version' => '2.9.1'], ['package' => 'barryvdh/laravel-debugbar', 'version' => '3.16.0']], $checks['dev_packages']['loaded']);

        foreach (['telescope', 'horizon', 'pulse', 'debugbar'] as $tool) {
            $this->assertTrue($checks['dev_tools'][$tool]['reachable_without_auth'], "{$tool} should be reachable");
        }

        $this->assertTrue($checks['dev_tools']['debugbar']['open_storage']);
    }

    #[Test]
    public function no_configured_value_reaches_the_wire()
    {
        $body = (string) $this->sentRequest()->getBody();

        foreach ($this->secrets as $secret) {
            if ($secret === 'secret-release-name') {
                continue;
            }

            $this->assertStringNotContainsString($secret, $body);
            $this->assertStringNotContainsString(json_encode($secret), $body);
        }

        $this->assertStringNotContainsString('secret-same-site-typo', $body);
    }

    #[Test]
    public function the_release_is_the_one_configured_value_sent()
    {
        $this->assertSame('secret-release-name', $this->sentPosture()['release']);
    }

    #[Test]
    public function every_string_in_the_snapshot_is_one_it_is_allowed_to_carry()
    {
        $posture = $this->sentPosture();

        $enums = [
            'trigger' => ['deploy', 'daily', 'manual'],
            'environment' => ['production'],
            'checks.session_cookie.same_site' => ['lax', 'strict', 'none', 'invalid'],
            'checks.trusted_proxies.source' => ['middleware', 'config', 'laravel_cloud'],
            'checks.dev_tools.*.gate' => ['none', 'allows_guests', 'denies_guests', 'unknown'],
            'checks.dev_packages.loaded.*.package' => [
                'spatie/laravel-ignition', 'facade/ignition', 'barryvdh/laravel-debugbar', 'fruitcake/laravel-debugbar',
                'itsgoingd/clockwork', 'spatie/laravel-web-tinker', 'beyondcode/laravel-query-detector', 'laravel/dusk',
            ],
        ];

        $formats = [
            'release' => '/^secret-release-name$/',
            'host' => '/^'.preg_quote((string) gethostname(), '/').'$/',
            'taken_at' => '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/',
            'php' => '/^'.preg_quote(PHP_VERSION, '/').'$/',
            'laravel' => '/^'.preg_quote(\Illuminate\Foundation\Application::VERSION, '/').'$/',
            'sdk' => '/^[\w.\-+]+$/',
            'checks.dev_packages.loaded.*.version' => '/^v?\d+\.\d+\.\d+$/',
        ];

        $strings = $this->strings($posture);

        $this->assertNotEmpty($strings);

        foreach ($strings as $path => $value) {
            $pattern = preg_replace('/\.(\d+|telescope|horizon|pulse|debugbar)(?=\.)/', '.*', $path);

            if (isset($enums[$pattern])) {
                $this->assertContains($value, $enums[$pattern], "{$path} carries a value outside its set");

                continue;
            }

            $this->assertArrayHasKey($pattern, $formats, "{$path} is a string field nobody allowed");
            $this->assertMatchesRegularExpression($formats[$pattern], $value, "{$path} is not shaped as expected");
        }
    }

    /**
     * @return array<string, string> Dotted path => string value.
     */
    protected function strings(array $values, string $prefix = ''): array
    {
        $strings = [];

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $strings += $this->strings($value, $path);
            } elseif (is_string($value)) {
                $strings[$path] = $value;
            }
        }

        return $strings;
    }

    protected function sentRequest(): \Psr\Http\Message\RequestInterface
    {
        if ($this->sent === []) {
            $this->artisan('larabug:security-posture')->assertExitCode(0);
        }

        $this->assertCount(1, $this->sent);

        return $this->sent[0]['request'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function sentPosture(): array
    {
        return json_decode((string) $this->sentRequest()->getBody(), true)['posture'];
    }
}
