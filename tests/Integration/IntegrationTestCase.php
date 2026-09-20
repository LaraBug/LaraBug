<?php

namespace LaraBug\Tests\Integration;

use LaraBug\LaraBug;
use LaraBug\Http\Client;
use LaraBug\ServiceProvider;
use LaraBug\Support\LimitBackoff;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as TestbenchTestCase;
use LaraBug\Tests\Integration\Support\RecordingTransport;

/**
 * Boots a real Laravel application with the package installed and lets the
 * package do its job, then reads the HTTP calls it made.
 *
 * The unit tests in tests/ build the pieces by hand and check each one. These
 * start from an application instead, so the wiring in ServiceProvider, the
 * buffers, the exception handler hook and the payload that actually leaves the
 * process are all covered. That is the part a consumer depends on and the part
 * a mismatch with the server shows up in.
 */
abstract class IntegrationTestCase extends TestbenchTestCase
{
    protected RecordingTransport $transport;

    /**
     * Whether this test is serving HTTP requests rather than running commands.
     *
     * The package only installs its request middleware outside the console,
     * and a test process is the console until it is told otherwise.
     */
    protected bool $servesHttpRequests = false;

    /**
     * The host every test points at. It is never contacted: the transport
     * answers before anything reaches a socket. A .test host is used anyway,
     * so a mistake here cannot reach a real install.
     */
    protected string $server = 'https://larabug-app.test/api/log';

    /**
     * The environments reporting is allowed from, or null to leave that to the
     * package's own config and whatever LB_ENVIRONMENTS says.
     *
     * A property rather than a line in defineEnvironment() because Testbench
     * applies the DefineEnvironment attributes before defineEnvironment(), so
     * anything set there would overrule the test that asked for something else.
     *
     * @var array<int, string>|null
     */
    protected ?array $reportingEnvironments = ['testing'];

    /**
     * Whether this test wants the CVE scanner. Off for the rest, because it
     * registers work on the scheduler and reads composer.lock.
     */
    protected bool $scansForVulnerabilities = false;

    protected function setUp(): void
    {
        if ($this->servesHttpRequests) {
            $_SERVER['APP_RUNNING_IN_CONSOLE'] = 'false';
        }

        parent::setUp();

        // Both are process wide, so a test that trips the backoff or leaves
        // context behind would otherwise change the test after it.
        LimitBackoff::clear();
        LaraBug::clearContext();

        $this->transport = new RecordingTransport();

        $this->app->make(Client::class)->setGuzzleHttpClient($this->transport->client());
    }

    protected function tearDown(): void
    {
        unset($_SERVER['APP_RUNNING_IN_CONSOLE']);

        parent::tearDown();
    }

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('larabug.server', $this->server);
        $app['config']->set('larabug.login_key', 'integration-login-key');
        $app['config']->set('larabug.project_key', 'integration-project-key');

        // Both keys are set because the package reads the environment two
        // ways: App::environment() when it decides whether to report, and
        // config('app.env') in the commands and on every payload.
        $app['config']->set('app.env', 'testing');

        if ($this->reportingEnvironments !== null) {
            $app['config']->set('larabug.environments', $this->reportingEnvironments);
        }

        // The heartbeat command is registered either way; this only stops it
        // being put on the scheduler of every test.
        $app['config']->set('larabug.heartbeat.enabled', false);
        $app['config']->set('larabug.cve.enabled', $this->scansForVulnerabilities);

        // A second identical exception inside the window is dropped, which
        // would make a test depend on the ones before it.
        $app['config']->set('larabug.sleep', 0);
    }

    /**
     * Build the client again from the config as it stands now.
     *
     * The credentials and the server are read once, when the container first
     * builds the client, so a test that changes them has to ask for a new one
     * the way a booting application would.
     */
    protected function rebuildClient(): void
    {
        $this->app->forgetInstance(Client::class);
        $this->app->forgetInstance('larabug');

        $this->app->make(Client::class)->setGuzzleHttpClient($this->transport->client());
    }
}
