<?php

namespace LaraBug\Tests;

use LaraBug\Cve\RequestTrigger;
use LaraBug\Scanners\ComposerLockScanner;
use LaraBug\Tests\Mocks\CveClient;
use ReflectionClass;

class CveRequestTriggerTest extends TestCase
{
    /** @var string */
    protected $lockPath;

    public function setUp(): void
    {
        parent::setUp();

        $this->lockPath = sys_get_temp_dir() . '/larabug-trigger-' . uniqid() . '.lock';
        $this->writeLock('v12.0.1');

        $this->app['config']['larabug.cve.enabled'] = true;
        $this->app['config']['larabug.cve.trigger'] = 'both';
        $this->app['config']['larabug.cve.lock_path'] = $this->lockPath;
        $this->app['config']['larabug.cve.request_throttle_hours'] = 24;

        // The trigger memoises per process; without this each test would inherit
        // the previous one's "already fired" and cached payload.
        $this->resetStaticState();
    }

    public function tearDown(): void
    {
        if (is_file($this->lockPath)) {
            unlink($this->lockPath);
        }

        $this->resetStaticState();

        parent::tearDown();
    }

    protected function resetStaticState(): void
    {
        $reflection = new ReflectionClass(RequestTrigger::class);

        foreach (['cachedPayload' => null, 'alreadyFired' => false] as $name => $value) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue(null, $value);
        }
    }

    protected function writeLock(string $version): void
    {
        file_put_contents($this->lockPath, json_encode([
            'packages' => [['name' => 'laravel/framework', 'version' => $version]],
        ]));
    }

    protected function trigger(CveClient $client): RequestTrigger
    {
        return new RequestTrigger($this->app['cache']->store('array'), new ComposerLockScanner(), $client);
    }

    /** @test */
    public function it_does_nothing_when_the_feature_is_disabled()
    {
        $this->app['config']['larabug.cve.enabled'] = false;

        $client = new CveClient();
        $this->trigger($client)->maybeTrigger();

        $client->assertRequestsSent(0);
    }

    /** @test */
    public function it_does_nothing_when_the_trigger_is_scheduled_only()
    {
        $this->app['config']['larabug.cve.trigger'] = 'schedule';

        $client = new CveClient();
        $this->trigger($client)->maybeTrigger();

        $client->assertRequestsSent(0);
    }

    /** @test */
    public function it_sends_the_lockfile_payload_tagged_as_a_cve_scan()
    {
        $client = new CveClient();
        $this->trigger($client)->maybeTrigger();

        $client->assertRequestsSent(1);

        $sent = $client->lastRequest();

        $this->assertSame('cve_scan', $sent['type']);
        $this->assertSame(['laravel/framework' => 'v12.0.1'], $sent['composer_lock']['packages']);
        $this->assertSame(64, strlen($sent['composer_lock']['content_hash']));
    }

    /** @test */
    public function it_only_fires_once_per_process()
    {
        $client = new CveClient();
        $trigger = $this->trigger($client);

        $trigger->maybeTrigger();
        $trigger->maybeTrigger();
        $trigger->maybeTrigger();

        $client->assertRequestsSent(1);
    }

    /** @test */
    public function it_stays_quiet_while_the_lockfile_is_unchanged_and_the_throttle_holds()
    {
        $first = new CveClient();
        $this->trigger($first)->maybeTrigger();
        $first->assertRequestsSent(1);

        // A second process, same unchanged lockfile.
        $this->resetStaticState();

        $second = new CveClient();
        $this->trigger($second)->maybeTrigger();

        $second->assertRequestsSent(0);
    }

    /** @test */
    public function it_fires_again_as_soon_as_the_lockfile_changes()
    {
        $first = new CveClient();
        $this->trigger($first)->maybeTrigger();
        $first->assertRequestsSent(1);

        $this->resetStaticState();
        $this->writeLock('v12.0.2');

        $second = new CveClient();
        $this->trigger($second)->maybeTrigger();

        $second->assertRequestsSent(1);
    }

    /** @test */
    public function it_fires_again_once_the_throttle_has_expired()
    {
        $first = new CveClient();
        $this->trigger($first)->maybeTrigger();
        $first->assertRequestsSent(1);

        $this->resetStaticState();

        // Same hash, but last sent more than the throttle window ago.
        $this->app['cache']->store('array')->put(
            'larabug.cve.last_sent_at',
            time() - (25 * 3600),
            3600,
        );

        $second = new CveClient();
        $this->trigger($second)->maybeTrigger();

        $second->assertRequestsSent(1);
    }

    /** @test */
    public function it_remembers_a_scan_the_server_says_it_already_has()
    {
        $client = new CveClient(200, '{"skipped":"unchanged","snapshot_id":"snap-1"}');
        $this->trigger($client)->maybeTrigger();

        $this->assertSame(64, strlen($this->app['cache']->store('array')->get('larabug.cve.last_sent_hash')));
    }

    /** @test */
    public function it_does_not_remember_a_rejected_scan_as_a_success()
    {
        // A 403 must not look like a delivered scan: the lockfile it describes
        // has not been recorded, so nothing should claim it has.
        $client = new CveClient(403, '{"error":"feature_disabled","feature":"cve"}');
        $this->trigger($client)->maybeTrigger();

        $client->assertRequestsSent(1);
        $this->assertNull($this->app['cache']->store('array')->get('larabug.cve.last_sent_hash'));
    }

    /** @test */
    public function it_is_on_for_a_config_that_predates_the_feature()
    {
        // A config file published before CVE scanning existed has no cve.enabled
        // key at all. Scanning is opt-out, so an absent key still scans.
        $cve = $this->app['config']['larabug.cve'];
        unset($cve['enabled']);
        $this->app['config']['larabug.cve'] = $cve;

        $this->assertNull($this->app['config']['larabug.cve.enabled']);

        $client = new CveClient();
        $this->trigger($client)->maybeTrigger();

        $client->assertRequestsSent(1);
    }

    /** @test */
    public function it_backs_off_after_a_403_instead_of_asking_on_every_request()
    {
        // Being on by default means most apps meet a project that has not
        // enabled scanning. Without a backoff every request would post the
        // lockfile again to collect the same 403.
        $rejected = new CveClient(403, '{"error":"feature_disabled","feature":"cve"}');
        $this->trigger($rejected)->maybeTrigger();
        $rejected->assertRequestsSent(1);

        foreach (range(1, 3) as $ignored) {
            $this->resetStaticState();

            $next = new CveClient();
            $this->trigger($next)->maybeTrigger();
            $next->assertRequestsSent(0);
        }
    }

    /** @test */
    public function it_tries_again_once_the_backoff_has_lapsed()
    {
        $rejected = new CveClient(403, '{"error":"feature_disabled","feature":"cve"}');
        $this->trigger($rejected)->maybeTrigger();

        $this->resetStaticState();
        $this->app['cache']->store('array')->forget('larabug.cve.backoff_until');

        $retry = new CveClient();
        $this->trigger($retry)->maybeTrigger();

        $retry->assertRequestsSent(1);
    }

    /** @test */
    public function it_keeps_retrying_after_a_server_error_rather_than_backing_off()
    {
        // A 5xx is a blip, not an answer, so it must not trip the backoff.
        $down = new CveClient(500, 'upstream is having a moment');
        $this->trigger($down)->maybeTrigger();
        $down->assertRequestsSent(1);

        $this->resetStaticState();

        $retry = new CveClient();
        $this->trigger($retry)->maybeTrigger();

        $retry->assertRequestsSent(1);
    }

    /** @test */
    public function it_does_nothing_when_there_is_no_lockfile_to_read()
    {
        $this->app['config']['larabug.cve.lock_path'] = '/tmp/larabug-no-such-file.lock';

        $client = new CveClient();
        $this->trigger($client)->maybeTrigger();

        $client->assertRequestsSent(0);
    }
}
