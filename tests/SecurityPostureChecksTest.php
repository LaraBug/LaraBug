<?php

namespace LaraBug\Tests;

use RuntimeException;
use Laravel\Horizon\Horizon;
use Laravel\Telescope\Telescope;
use Illuminate\Support\Facades\Gate;
use LaraBug\Security\PostureSnapshot;
use PHPUnit\Framework\Attributes\Test;
use LaraBug\Security\InstalledPackages;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Middleware\TrustProxies;
use LaraBug\Tests\Mocks\FakeInstalledPackages;
use Illuminate\Contracts\Http\Kernel as HttpKernel;

class SecurityPostureChecksTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        require_once __DIR__.'/Fixtures/SecurityTools.php';

        Telescope::$authUsing = null;
        Horizon::$authUsing = null;

        $this->app->instance(InstalledPackages::class, new FakeInstalledPackages());

        $this->inEnvironment('production');
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();

        unset($_ENV['LARAVEL_CLOUD'], $_SERVER['LARAVEL_CLOUD']);

        parent::tearDown();
    }

    #[Test]
    public function debug_mode_is_reported_on()
    {
        config(['app.debug' => true]);

        $this->assertSame(['enabled' => true], $this->check('debug_mode'));
    }

    #[Test]
    public function debug_mode_is_reported_off()
    {
        config(['app.debug' => false]);

        $this->assertSame(['enabled' => false], $this->check('debug_mode'));
    }

    #[Test]
    public function the_snapshot_says_whether_the_environment_is_production()
    {
        $this->assertTrue($this->snapshot()['production']);
        $this->assertSame('production', $this->snapshot()['environment']);

        $this->inEnvironment('staging');

        $this->assertFalse($this->snapshot()['production']);
        $this->assertSame('staging', $this->snapshot()['environment']);
    }

    #[Test]
    public function a_session_cookie_with_every_flag_is_reported_as_such()
    {
        config(['session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'Strict']);

        $this->assertSame(['secure' => true, 'http_only' => true, 'same_site' => 'strict'], $this->check('session_cookie'));
    }

    #[Test]
    public function a_session_cookie_without_its_flags_is_reported_as_such()
    {
        config(['session.secure' => null, 'session.http_only' => false, 'session.same_site' => null]);

        $this->assertSame(['secure' => false, 'http_only' => false, 'same_site' => null], $this->check('session_cookie'));
    }

    #[Test]
    public function a_same_site_value_no_browser_knows_is_reported_as_invalid_rather_than_sent()
    {
        config(['session.same_site' => 'whatever-was-typed']);

        $this->assertSame('invalid', $this->check('session_cookie')['same_site']);

        config(['session.same_site' => 'none']);

        $this->assertSame('none', $this->check('session_cookie')['same_site']);
    }

    #[Test]
    public function proxies_trusted_in_bootstrap_app_as_a_wildcard_trust_all()
    {
        TrustProxies::at('*');

        $this->assertSame(['configured' => true, 'trusts_all' => true, 'source' => 'middleware'], $this->check('trusted_proxies'));

        TrustProxies::at('**');

        $this->assertTrue($this->check('trusted_proxies')['trusts_all']);
    }

    #[Test]
    public function proxies_trusted_by_address_do_not_trust_all()
    {
        TrustProxies::at(['10.0.0.1', '10.0.0.2']);

        $this->assertSame(['configured' => true, 'trusts_all' => false, 'source' => 'middleware'], $this->check('trusted_proxies'));

        TrustProxies::at('10.0.0.1,10.0.0.2');

        $this->assertFalse($this->check('trusted_proxies')['trusts_all']);
    }

    #[Test]
    public function a_list_that_covers_every_address_trusts_all()
    {
        TrustProxies::at(['REMOTE_ADDR']);

        $this->assertTrue($this->check('trusted_proxies')['trusts_all']);

        TrustProxies::at(['10.0.0.1', '0.0.0.0/0']);

        $this->assertTrue($this->check('trusted_proxies')['trusts_all']);
    }

    #[Test]
    public function proxies_trusted_through_the_trustedproxy_config_are_read_too()
    {
        config(['trustedproxy.proxies' => '*']);

        $this->assertSame(['configured' => true, 'trusts_all' => true, 'source' => 'config'], $this->check('trusted_proxies'));
    }

    #[Test]
    public function proxies_set_on_an_applications_own_middleware_are_read_too()
    {
        $this->app->make(HttpKernel::class)->setGlobalMiddleware([AppTrustProxies::class]);

        $this->assertSame(['configured' => true, 'trusts_all' => true, 'source' => 'middleware'], $this->check('trusted_proxies'));
    }

    #[Test]
    public function no_proxy_trusted_is_reported_as_not_configured()
    {
        $this->assertSame(['configured' => false, 'trusts_all' => false, 'source' => null], $this->check('trusted_proxies'));
    }

    #[Test]
    public function nothing_is_trusted_when_the_middleware_is_not_in_the_global_stack()
    {
        config(['trustedproxy.proxies' => '*']);

        $this->app->make(HttpKernel::class)->setGlobalMiddleware([HandleCors::class]);

        $this->assertSame(['configured' => false, 'trusts_all' => false, 'source' => null], $this->check('trusted_proxies'));
    }

    #[Test]
    public function laravel_cloud_trusts_every_proxy_when_nothing_else_is_set()
    {
        $_ENV['LARAVEL_CLOUD'] = '1';

        $this->assertSame(['configured' => true, 'trusts_all' => true, 'source' => 'laravel_cloud'], $this->check('trusted_proxies'));
    }

    #[Test]
    public function cors_allowing_any_origin_with_credentials_is_reported()
    {
        config(['cors' => [
            'paths' => ['api/*'],
            'allowed_origins' => ['*'],
            'allowed_origins_patterns' => [],
            'supports_credentials' => true,
        ]]);

        $this->assertSame(['applies' => true, 'allows_any_origin' => true, 'supports_credentials' => true], $this->check('cors'));
    }

    #[Test]
    public function cors_limited_to_named_origins_does_not_allow_any_origin()
    {
        config(['cors' => [
            'paths' => ['api/*'],
            'allowed_origins' => ['https://app.example.com'],
            'allowed_origins_patterns' => ['#^https://[a-z]+\.example\.com$#'],
            'supports_credentials' => true,
        ]]);

        $this->assertSame(['applies' => true, 'allows_any_origin' => false, 'supports_credentials' => true], $this->check('cors'));
    }

    #[Test]
    public function cors_patterns_and_wildcard_origins_that_match_anything_count_as_any_origin()
    {
        config(['cors' => ['paths' => ['api/*'], 'allowed_origins' => [], 'allowed_origins_patterns' => ['#.*#']]]);

        $this->assertTrue($this->check('cors')['allows_any_origin']);

        config(['cors' => ['paths' => ['api/*'], 'allowed_origins' => ['https://*'], 'allowed_origins_patterns' => []]]);

        $this->assertTrue($this->check('cors')['allows_any_origin']);
    }

    #[Test]
    public function cors_without_credentials_is_reported_as_such()
    {
        config(['cors' => ['paths' => ['api/*'], 'allowed_origins' => ['*'], 'supports_credentials' => false]]);

        $this->assertFalse($this->check('cors')['supports_credentials']);
    }

    #[Test]
    public function cors_does_not_apply_without_paths_or_without_its_middleware()
    {
        config(['cors' => ['paths' => [], 'allowed_origins' => ['*']]]);

        $this->assertFalse($this->check('cors')['applies']);

        config(['cors' => ['paths' => ['api/*'], 'allowed_origins' => ['*']]]);

        $this->app->make(HttpKernel::class)->setGlobalMiddleware([TrustProxies::class]);

        $this->assertFalse($this->check('cors')['applies']);
    }

    #[Test]
    public function dev_packages_loaded_in_production_are_named_with_their_version()
    {
        $this->packages(['spatie/laravel-ignition' => '2.9.1', 'laravel/dusk' => '8.3.0'], devInstalled: true);

        $this->app->register(\Spatie\LaravelIgnition\IgnitionServiceProvider::class);
        $this->app->register(\Laravel\Dusk\DuskServiceProvider::class);

        $this->assertSame([
            'composer_dev_installed' => true,
            'loaded' => [
                ['package' => 'spatie/laravel-ignition', 'version' => '2.9.1'],
                ['package' => 'laravel/dusk', 'version' => '8.3.0'],
            ],
        ], $this->check('dev_packages'));
    }

    #[Test]
    public function no_dev_packages_loaded_is_reported_as_an_empty_list()
    {
        $this->packages(['spatie/laravel-ignition' => '2.9.1'], devInstalled: false);

        $this->assertSame(['composer_dev_installed' => false, 'loaded' => []], $this->check('dev_packages'));
    }

    #[Test]
    public function telescope_open_to_guests_is_reachable_without_auth()
    {
        $this->packages(['laravel/telescope' => '5.10.0']);

        $this->telescope(['web', 'Laravel\Telescope\Http\Middleware\Authorize']);

        $this->telescopeAuthorisesThroughItsGate();

        Gate::define('viewTelescope', fn ($user = null) => true);

        $this->assertSame([
            'installed' => true,
            'enabled' => true,
            'routed' => true,
            'auth_middleware' => false,
            'gate' => 'allows_guests',
            'reachable_without_auth' => true,
        ], $this->tool('telescope'));
    }

    #[Test]
    public function telescope_behind_its_stock_gate_is_not_reachable_without_auth()
    {
        $this->telescope(['web', 'Laravel\Telescope\Http\Middleware\Authorize']);

        $this->telescopeAuthorisesThroughItsGate();

        Gate::define('viewTelescope', fn ($user) => in_array($user->email, ['admin@example.com'], true));

        $this->assertSame('denies_guests', $this->tool('telescope')['gate']);
        $this->assertFalse($this->tool('telescope')['reachable_without_auth']);
    }

    #[Test]
    public function telescope_without_its_authorize_middleware_is_reachable_whatever_the_gate_says()
    {
        $this->telescope(['web']);

        Gate::define('viewTelescope', fn ($user) => false);

        $this->assertSame('none', $this->tool('telescope')['gate']);
        $this->assertTrue($this->tool('telescope')['reachable_without_auth']);
    }

    #[Test]
    public function telescope_behind_auth_middleware_is_not_reachable_without_auth()
    {
        $this->telescope(['web', 'auth', 'Laravel\Telescope\Http\Middleware\Authorize']);

        Telescope::auth(fn () => true);

        $tool = $this->tool('telescope');

        $this->assertTrue($tool['auth_middleware']);
        $this->assertSame('allows_guests', $tool['gate']);
        $this->assertFalse($tool['reachable_without_auth']);
    }

    #[Test]
    public function a_telescope_gate_that_throws_is_unknown_and_not_reported_as_reachable()
    {
        $this->telescope(['web', 'Laravel\Telescope\Http\Middleware\Authorize']);

        Telescope::auth(fn () => throw new RuntimeException('The database is down'));

        $this->assertSame('unknown', $this->tool('telescope')['gate']);
        $this->assertFalse($this->tool('telescope')['reachable_without_auth']);
    }

    #[Test]
    public function telescope_switched_off_is_not_enabled_nor_routed()
    {
        $this->packages(['laravel/telescope' => '5.10.0']);

        config(['telescope.enabled' => false]);

        $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);

        $this->assertSame([
            'installed' => true,
            'enabled' => false,
            'routed' => false,
            'auth_middleware' => false,
            'gate' => 'none',
            'reachable_without_auth' => false,
        ], $this->tool('telescope'));
    }

    #[Test]
    public function telescope_not_loaded_is_not_enabled()
    {
        $this->assertSame([
            'installed' => false,
            'enabled' => false,
            'routed' => false,
            'auth_middleware' => false,
            'gate' => 'none',
            'reachable_without_auth' => false,
        ], $this->tool('telescope'));
    }

    #[Test]
    public function horizon_open_to_guests_is_reachable_without_auth()
    {
        $this->packages(['laravel/horizon' => '5.40.0']);

        $this->app->register(\Laravel\Horizon\HorizonServiceProvider::class);

        Horizon::auth(fn ($request) => true);

        $this->assertSame([
            'installed' => true,
            'enabled' => true,
            'routed' => true,
            'auth_middleware' => false,
            'gate' => 'allows_guests',
            'reachable_without_auth' => true,
        ], $this->tool('horizon'));
    }

    #[Test]
    public function horizon_behind_its_stock_gate_is_not_reachable_without_auth()
    {
        $this->app->register(\Laravel\Horizon\HorizonServiceProvider::class);

        Gate::define('viewHorizon', fn ($user) => in_array($user->email, ['admin@example.com'], true));
        Horizon::auth(fn ($request) => app()->environment('local') || Gate::check('viewHorizon', [$request->user()]));

        $this->assertSame('denies_guests', $this->tool('horizon')['gate']);
        $this->assertFalse($this->tool('horizon')['reachable_without_auth']);
    }

    #[Test]
    public function horizon_without_any_auth_callback_is_closed_outside_local()
    {
        $this->app->register(\Laravel\Horizon\HorizonServiceProvider::class);

        $this->assertFalse($this->tool('horizon')['reachable_without_auth']);

        $this->inEnvironment('local');

        $this->assertTrue($this->tool('horizon')['reachable_without_auth']);
    }

    #[Test]
    public function horizon_not_loaded_is_not_enabled()
    {
        $this->assertFalse($this->tool('horizon')['enabled']);
        $this->assertFalse($this->tool('horizon')['reachable_without_auth']);
    }

    #[Test]
    public function pulse_open_to_guests_is_reachable_without_auth()
    {
        $this->pulse(['web', 'Laravel\Pulse\Http\Middleware\Authorize']);

        Gate::define('viewPulse', fn ($user = null) => true);

        $this->assertSame('allows_guests', $this->tool('pulse')['gate']);
        $this->assertTrue($this->tool('pulse')['reachable_without_auth']);
    }

    #[Test]
    public function pulse_behind_its_stock_gate_is_not_reachable_without_auth()
    {
        $this->pulse(['web', 'Laravel\Pulse\Http\Middleware\Authorize']);

        $this->assertSame('denies_guests', $this->tool('pulse')['gate']);
        $this->assertFalse($this->tool('pulse')['reachable_without_auth']);
    }

    #[Test]
    public function pulse_without_its_authorize_middleware_is_reachable()
    {
        $this->pulse(['web']);

        $this->assertSame('none', $this->tool('pulse')['gate']);
        $this->assertTrue($this->tool('pulse')['reachable_without_auth']);
    }

    #[Test]
    public function pulse_switched_off_is_not_enabled()
    {
        config(['pulse.enabled' => false]);

        $this->app->register(\Laravel\Pulse\PulseServiceProvider::class);

        $this->assertFalse($this->tool('pulse')['enabled']);
        $this->assertFalse($this->tool('pulse')['routed']);
    }

    #[Test]
    public function debugbar_forced_on_in_production_is_reachable_without_auth()
    {
        $this->packages(['fruitcake/laravel-debugbar' => '4.2.0']);

        config(['app.debug' => true, 'debugbar.force_allow_enable' => true, 'debugbar.enabled' => null]);

        $this->app->register(\Fruitcake\LaravelDebugbar\ServiceProvider::class);

        $this->assertSame([
            'installed' => true,
            'enabled' => true,
            'open_storage' => false,
            'reachable_without_auth' => true,
        ], $this->tool('debugbar'));
    }

    #[Test]
    public function debugbar_refuses_production_on_its_own_unless_forced()
    {
        config(['app.debug' => true, 'debugbar.enabled' => true]);

        $this->app->register(\Fruitcake\LaravelDebugbar\ServiceProvider::class);

        $this->assertFalse($this->tool('debugbar')['enabled']);
    }

    #[Test]
    public function the_older_debugbar_follows_app_debug_in_production()
    {
        $this->packages(['barryvdh/laravel-debugbar' => '3.16.0']);

        config(['app.debug' => true, 'debugbar.enabled' => null]);

        $this->app->register(\Barryvdh\Debugbar\ServiceProvider::class);

        $this->assertTrue($this->tool('debugbar')['enabled']);
        $this->assertTrue($this->tool('debugbar')['reachable_without_auth']);

        config(['app.debug' => false]);

        $this->assertFalse($this->tool('debugbar')['enabled']);

        config(['app.debug' => true, 'debugbar.enabled' => fn () => false]);

        $this->assertFalse($this->tool('debugbar')['enabled']);
    }

    #[Test]
    public function debugbar_with_open_storage_is_reported()
    {
        config(['app.debug' => true, 'debugbar.storage.open' => true]);

        $this->app->register(\Barryvdh\Debugbar\ServiceProvider::class);

        $this->assertTrue($this->tool('debugbar')['open_storage']);
    }

    #[Test]
    public function debugbar_installed_but_not_loaded_is_not_enabled()
    {
        $this->packages(['barryvdh/laravel-debugbar' => '3.16.0']);

        config(['app.debug' => true]);

        $this->assertSame([
            'installed' => true,
            'enabled' => false,
            'open_storage' => false,
            'reachable_without_auth' => false,
        ], $this->tool('debugbar'));
    }

    #[Test]
    public function a_check_that_throws_is_null_and_the_rest_of_the_snapshot_is_still_taken()
    {
        $this->app->bind(\LaraBug\Security\Checks\CorsCheck::class, fn () => throw new RuntimeException('Surprise'));
        $this->app->bind(\LaraBug\Security\Checks\PulseCheck::class, fn () => throw new RuntimeException('Surprise'));

        config(['app.debug' => true]);

        $snapshot = $this->snapshot();

        $this->assertNull($snapshot['checks']['cors']);
        $this->assertNull($snapshot['checks']['dev_tools']['pulse']);
        $this->assertSame(['enabled' => true], $snapshot['checks']['debug_mode']);
        $this->assertIsArray($snapshot['checks']['dev_tools']['telescope']);
    }

    #[Test]
    public function the_snapshot_carries_the_versions_the_sdk_header_carries()
    {
        config(['larabug.project_version' => 'abc1234']);

        $snapshot = $this->snapshot();

        $this->assertSame(1, $snapshot['schema']);
        $this->assertSame('daily', $snapshot['trigger']);
        $this->assertSame('abc1234', $snapshot['release']);
        $this->assertSame(PHP_VERSION, $snapshot['php']);
        $this->assertSame(\Illuminate\Foundation\Application::VERSION, $snapshot['laravel']);
        $this->assertStringContainsString("php/{$snapshot['php']}", \LaraBug\Support\SdkVersion::header());
        $this->assertStringContainsString("laravel/{$snapshot['laravel']}", \LaraBug\Support\SdkVersion::header());
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $snapshot['taken_at']);
    }

    protected function inEnvironment(string $environment): void
    {
        $this->app['env'] = $environment;

        config(['app.env' => $environment]);
    }

    /**
     * @param  array<string, string>  $packages
     */
    protected function packages(array $packages, ?bool $devInstalled = false): void
    {
        $this->app->instance(InstalledPackages::class, new FakeInstalledPackages($packages, $devInstalled));
    }

    /**
     * @param  array<int, string>  $middleware
     */
    protected function telescope(array $middleware): void
    {
        config(['telescope.enabled' => true, 'telescope.middleware' => $middleware]);

        $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
    }

    /**
     * What the TelescopeApplicationServiceProvider an application publishes
     * does in authorization().
     */
    protected function telescopeAuthorisesThroughItsGate(): void
    {
        Telescope::auth(fn ($request) => app()->environment('local') || Gate::check('viewTelescope', [$request->user()]));
    }

    /**
     * @param  array<int, string>  $middleware
     */
    protected function pulse(array $middleware): void
    {
        config(['pulse.enabled' => true, 'pulse.middleware' => $middleware]);

        $this->app->register(\Laravel\Pulse\PulseServiceProvider::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function snapshot(): array
    {
        return $this->app->make(PostureSnapshot::class)->take('daily');
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function check(string $key): ?array
    {
        return $this->snapshot()['checks'][$key];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function tool(string $key): ?array
    {
        return $this->snapshot()['checks']['dev_tools'][$key];
    }
}

/**
 * An application's own middleware as Laravel 10 and earlier generated it, kept
 * after the upgrade.
 */
class AppTrustProxies extends TrustProxies
{
    protected $proxies = '*';
}
