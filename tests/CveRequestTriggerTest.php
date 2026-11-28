<?php

namespace LaraBug\Tests;

use ReflectionClass;
use LaraBug\Cve\RequestTrigger;
use LaraBug\Tests\Mocks\CveClient;
use LaraBug\Scanners\NpmLockScanner;
use PHPUnit\Framework\Attributes\Test;
use LaraBug\Scanners\ComposerLockScanner;

class CveRequestTriggerTest extends TestCase
{
    protected string $lockPath;

    protected string $npmDirectory;

    protected string $settledPath;

    public function setUp(): void
    {
        parent::setUp();

        $this->lockPath = sys_get_temp_dir() . '/larabug-trigger-' . uniqid() . '.lock';
        $this->writeLock('v12.0.1');

        $this->npmDirectory = sys_get_temp_dir() . '/larabug-trigger-npm-' . uniqid();
        mkdir($this->npmDirectory);

        $this->settledPath = sys_get_temp_dir() . '/larabug-trigger-settled-' . uniqid();

        $this->app['config']['larabug.cve.enabled'] = true;
        $this->app['config']['larabug.cve.trigger'] = 'both';
        $this->app['config']['larabug.cve.lock_path'] = $this->lockPath;
        $this->app['config']['larabug.cve.request_throttle_hours'] = 24;
        $this->app['config']['larabug.cve.npm'] = true;

        // The trigger memoises per process; without a reset each test would inherit the previous one's state.
        $this->resetStaticState();
    }

    public function tearDown(): void
    {
        if (is_file($this->lockPath)) {
            unlink($this->lockPath);
        }

        foreach (glob("{$this->npmDirectory}/*") as $file) {
            unlink($file);
        }

        rmdir($this->npmDirectory);

        if (is_file($this->settledPath)) {
            unlink($this->settledPath);
        }

        $this->resetStaticState();

        parent::tearDown();
    }

    protected function resetStaticState(): void
    {
        $reflection = new ReflectionClass(RequestTrigger::class);

        foreach (['cachedPayload' => null, 'alreadyFired' => false] as $name => $value) {
            $reflection->getProperty($name)->setValue(null, $value);
        }
    }

    protected function writeLock(string $version): void
    {
        file_put_contents($this->lockPath, json_encode([
            'packages' => [['name' => 'laravel/framework', 'version' => $version]],
        ]));
    }

    protected function writeNpmLock(string $version): void
    {
        file_put_contents("{$this->npmDirectory}/package-lock.json", json_encode([
            'lockfileVersion' => 3,
            'packages' => [
                '' => ['name' => 'app'],
                'node_modules/lodash' => ['version' => $version],
            ],
        ]));
    }

    /**
     * Old enough that what stat says about the lockfiles can be trusted: one
     * written within the last second is always hashed.
     */
    protected function ageLockfiles(int $seconds = 60): void
    {
        touch($this->lockPath, time() - $seconds);

        foreach (glob("{$this->npmDirectory}/*") as $file) {
            touch($file, time() - $seconds);
        }
    }

    /**
     * @return array{0: ComposerLockScanner, 1: NpmLockScanner}
     */
    protected function scannersCountingHashes(): array
    {
        $composerScanner = new class () extends ComposerLockScanner {
            public int $hashes = 0;

            public function hash(?string $lockPath = null): ?string
            {
                $this->hashes++;

                return parent::hash($lockPath);
            }
        };

        $npmScanner = new class ($this->npmDirectory) extends NpmLockScanner {
            public int $hashes = 0;

            public function hash(): ?string
            {
                $this->hashes++;

                return parent::hash();
            }
        };

        return [$composerScanner, $npmScanner];
    }

    protected function trigger(CveClient $client): RequestTrigger
    {
        return new RequestTrigger(
            $this->app['cache']->store('array'),
            new ComposerLockScanner(),
            new NpmLockScanner($this->npmDirectory),
            $client,
            $this->settledPath,
        );
    }

    #[Test]
    public function it_does_nothing_when_the_feature_is_disabled()
    {
        $this->app['config']['larabug.cve.enabled'] = false;

        $client = new CveClient();
        $this->trigger($client)->maybeTrigger();

        $client->assertRequestsSent(0);
    }

    #[Test]
    public function it_does_nothing_when_the_trigger_is_scheduled_only()
    {
        $this->app['config']['larabug.cve.trigger'] = 'schedule';

        $client = new CveClient();
        $this->trigger($client)->maybeTrigger();

        $client->assertRequestsSent(0);
    }

    #[Test]
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

    #[Test]
    public function it_only_fires_once_per_process()
    {
        $client = new CveClient();
        $trigger = $this->trigger($client);

        $trigger->maybeTrigger();
        $trigger->maybeTrigger();
        $trigger->maybeTrigger();

        $client->assertRequestsSent(1);
    }

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
    public function it_remembers_a_scan_the_server_says_it_already_has()
    {
        $client = new CveClient(200, '{"skipped":"unchanged","snapshot_id":"snap-1"}');
        $this->trigger($client)->maybeTrigger();

        $this->assertSame(64, strlen($this->app['cache']->store('array')->get('larabug.cve.last_sent_hash')));
    }

    #[Test]
    public function it_does_not_remember_a_rejected_scan_as_a_success()
    {
        // A 403 must not look like a delivered scan; nothing may claim its lockfile was recorded.
        $client = new CveClient(403, '{"error":"feature_disabled","feature":"cve"}');
        $this->trigger($client)->maybeTrigger();

        $client->assertRequestsSent(1);
        $this->assertNull($this->app['cache']->store('array')->get('larabug.cve.last_sent_hash'));
    }

    #[Test]
    public function it_is_on_for_a_config_that_predates_the_feature()
    {
        // A config published before the feature has no cve.enabled key; scanning is opt-out, so an absent key still scans.
        $cve = $this->app['config']['larabug.cve'];
        unset($cve['enabled']);
        $this->app['config']['larabug.cve'] = $cve;

        $this->assertNull($this->app['config']['larabug.cve.enabled']);

        $client = new CveClient();
        $this->trigger($client)->maybeTrigger();

        $client->assertRequestsSent(1);
    }

    #[Test]
    public function it_backs_off_after_a_403_instead_of_asking_on_every_request()
    {
        // Without a backoff, every request would post the lockfile again to collect the same 403.
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

    #[Test]
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

    #[Test]
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

    #[Test]
    public function it_does_nothing_when_there_is_no_lockfile_to_read()
    {
        $this->app['config']['larabug.cve.lock_path'] = '/tmp/larabug-no-such-file.lock';

        $client = new CveClient();
        $this->trigger($client)->maybeTrigger();

        $client->assertRequestsSent(0);
    }

    #[Test]
    public function it_sends_composer_lock_alone_when_there_is_no_npm_lockfile()
    {
        $client = new CveClient();
        $this->trigger($client)->maybeTrigger();

        $this->assertSame(['type', 'composer_lock'], array_keys($client->lastRequest()));
    }

    #[Test]
    public function it_sends_the_npm_lockfile_next_to_composer_lock_in_one_scan()
    {
        $this->writeNpmLock('4.17.21');

        $client = new CveClient();
        $this->trigger($client)->maybeTrigger();

        $client->assertRequestsSent(1);

        $sent = $client->lastRequest();

        $this->assertSame(['type', 'composer_lock', 'npm_lock'], array_keys($sent));
        $this->assertSame(['laravel/framework' => 'v12.0.1'], $sent['composer_lock']['packages']);
        $this->assertSame('package-lock.json', $sent['npm_lock']['lockfile']);
        $this->assertSame([['name' => 'lodash', 'version' => '4.17.21', 'dev' => false]], $sent['npm_lock']['packages']);
        $this->assertSame('php-sdk', $sent['npm_lock']['source']);
    }

    #[Test]
    public function it_sends_npm_lock_alone_for_a_project_without_composer_lock()
    {
        $this->app['config']['larabug.cve.lock_path'] = '/tmp/larabug-no-such-file.lock';
        $this->writeNpmLock('4.17.21');

        $client = new CveClient();
        $this->trigger($client)->maybeTrigger();

        $client->assertRequestsSent(1);
        $this->assertSame(['type', 'npm_lock'], array_keys($client->lastRequest()));
    }

    #[Test]
    public function it_leaves_the_npm_lockfile_out_when_npm_scanning_is_off()
    {
        $this->app['config']['larabug.cve.npm'] = false;
        $this->writeNpmLock('4.17.21');

        $client = new CveClient();
        $this->trigger($client)->maybeTrigger();

        $this->assertSame(['type', 'composer_lock'], array_keys($client->lastRequest()));
    }

    #[Test]
    public function it_remembers_the_last_sent_hash_of_each_lockfile()
    {
        $this->writeNpmLock('4.17.21');

        $client = new CveClient();
        $this->trigger($client)->maybeTrigger();

        $sent = $client->lastRequest();
        $cache = $this->app['cache']->store('array');

        $this->assertSame($sent['composer_lock']['content_hash'], $cache->get('larabug.cve.last_sent_hash'));
        $this->assertSame($sent['npm_lock']['content_hash'], $cache->get('larabug.cve.last_sent_npm_hash'));
    }

    #[Test]
    public function it_stays_quiet_while_neither_lockfile_changes()
    {
        $this->writeNpmLock('4.17.21');

        $first = new CveClient();
        $this->trigger($first)->maybeTrigger();
        $first->assertRequestsSent(1);

        $this->resetStaticState();

        $second = new CveClient();
        $this->trigger($second)->maybeTrigger();

        $second->assertRequestsSent(0);
    }

    #[Test]
    public function it_fires_again_when_only_the_npm_lockfile_changes()
    {
        $this->writeNpmLock('4.17.20');

        $first = new CveClient();
        $this->trigger($first)->maybeTrigger();
        $first->assertRequestsSent(1);

        $this->resetStaticState();
        $this->writeNpmLock('4.17.21');

        $second = new CveClient();
        $this->trigger($second)->maybeTrigger();

        $second->assertRequestsSent(1);
        $this->assertSame('4.17.21', $second->lastRequest()['npm_lock']['packages'][0]['version']);
        $this->assertArrayHasKey('composer_lock', $second->lastRequest());
    }

    #[Test]
    public function it_fires_once_after_upgrading_when_only_composer_lock_was_sent_before()
    {
        $first = new CveClient();
        $this->trigger($first)->maybeTrigger();
        $first->assertRequestsSent(1);

        $this->resetStaticState();
        $this->writeNpmLock('4.17.21');

        $second = new CveClient();
        $this->trigger($second)->maybeTrigger();

        $second->assertRequestsSent(1);
    }

    #[Test]
    public function it_does_not_parse_a_lockfile_whose_hash_says_no_scan_is_due()
    {
        $this->writeNpmLock('4.17.21');

        $first = new CveClient();
        $this->trigger($first)->maybeTrigger();
        $first->assertRequestsSent(1);

        $this->resetStaticState();

        $composerScanner = new class () extends ComposerLockScanner {
            public int $scans = 0;

            public function scan(?string $lockPath = null, bool $includeDev = false, ?string $environment = null): ?array
            {
                $this->scans++;

                return parent::scan($lockPath, $includeDev, $environment);
            }
        };

        $npmScanner = new class ($this->npmDirectory) extends NpmLockScanner {
            public int $scans = 0;

            public function scan(?string $environment = null): ?array
            {
                $this->scans++;

                return parent::scan($environment);
            }
        };

        $second = new CveClient();
        (new RequestTrigger($this->app['cache']->store('array'), $composerScanner, $npmScanner, $second, $this->settledPath))->maybeTrigger();

        $second->assertRequestsSent(0);
        $this->assertSame(0, $composerScanner->scans);
        $this->assertSame(0, $npmScanner->scans);
    }

    #[Test]
    public function it_does_not_hash_lockfiles_that_look_the_same_as_when_their_scan_landed()
    {
        $this->writeNpmLock('4.17.21');
        $this->ageLockfiles();

        $first = new CveClient();
        $this->trigger($first)->maybeTrigger();
        $first->assertRequestsSent(1);

        [$composerScanner, $npmScanner] = $this->scannersCountingHashes();

        foreach (range(1, 3) as $ignored) {
            $this->resetStaticState();

            $next = new CveClient();
            (new RequestTrigger($this->app['cache']->store('array'), $composerScanner, $npmScanner, $next, $this->settledPath))->maybeTrigger();

            $next->assertRequestsSent(0);
        }

        $this->assertSame(0, $composerScanner->hashes);
        $this->assertSame(0, $npmScanner->hashes);
    }

    #[Test]
    public function it_hashes_a_lockfile_written_within_the_last_second()
    {
        $first = new CveClient();
        $this->trigger($first)->maybeTrigger();
        $first->assertRequestsSent(1);

        $this->resetStaticState();

        [$composerScanner, $npmScanner] = $this->scannersCountingHashes();

        $second = new CveClient();
        (new RequestTrigger($this->app['cache']->store('array'), $composerScanner, $npmScanner, $second, $this->settledPath))->maybeTrigger();

        $second->assertRequestsSent(0);
        $this->assertSame(1, $composerScanner->hashes);
    }

    #[Test]
    public function it_fires_again_when_a_lockfile_that_looked_settled_changes()
    {
        $this->ageLockfiles();

        $first = new CveClient();
        $this->trigger($first)->maybeTrigger();
        $first->assertRequestsSent(1);

        $this->resetStaticState();
        $this->writeLock('v12.0.2');
        $this->ageLockfiles(30);

        $second = new CveClient();
        $this->trigger($second)->maybeTrigger();

        $second->assertRequestsSent(1);
        $this->assertSame(['laravel/framework' => 'v12.0.2'], $second->lastRequest()['composer_lock']['packages']);
    }

    #[Test]
    public function it_fires_again_once_the_throttle_has_expired_for_lockfiles_that_look_the_same()
    {
        $this->ageLockfiles();

        $first = new CveClient();
        $this->trigger($first)->maybeTrigger();
        $first->assertRequestsSent(1);

        $this->resetStaticState();
        $this->travel(25)->hours();

        $second = new CveClient();
        $this->trigger($second)->maybeTrigger();

        $second->assertRequestsSent(1);
    }

    #[Test]
    public function it_does_not_hash_the_lockfiles_while_a_403_backoff_holds()
    {
        $this->ageLockfiles();

        $rejected = new CveClient(403, '{"error":"feature_disabled","feature":"cve"}');
        $this->trigger($rejected)->maybeTrigger();
        $rejected->assertRequestsSent(1);

        $this->resetStaticState();

        [$composerScanner, $npmScanner] = $this->scannersCountingHashes();

        $next = new CveClient();
        (new RequestTrigger($this->app['cache']->store('array'), $composerScanner, $npmScanner, $next, $this->settledPath))->maybeTrigger();

        $next->assertRequestsSent(0);
        $this->assertSame(0, $composerScanner->hashes);
    }

    #[Test]
    public function it_tries_again_once_the_backoff_has_lapsed_for_lockfiles_that_look_the_same()
    {
        $this->ageLockfiles();

        $rejected = new CveClient(403, '{"error":"feature_disabled","feature":"cve"}');
        $this->trigger($rejected)->maybeTrigger();

        $this->resetStaticState();
        $this->travel(61)->minutes();

        $retry = new CveClient();
        $this->trigger($retry)->maybeTrigger();

        $retry->assertRequestsSent(1);
    }

    #[Test]
    public function it_keeps_retrying_after_a_server_error_for_lockfiles_that_look_the_same()
    {
        $this->ageLockfiles();

        $down = new CveClient(500, 'upstream is having a moment');
        $this->trigger($down)->maybeTrigger();
        $down->assertRequestsSent(1);

        $this->resetStaticState();

        $retry = new CveClient();
        $this->trigger($retry)->maybeTrigger();

        $retry->assertRequestsSent(1);
    }

    #[Test]
    public function it_settles_lockfiles_whose_scan_landed_from_another_server()
    {
        $this->ageLockfiles();

        $first = new CveClient();
        $this->trigger($first)->maybeTrigger();
        $first->assertRequestsSent(1);

        // What another server leaves behind: the sent hashes in the cache, without a settled mark on this disk.
        unlink($this->settledPath);

        [$composerScanner, $npmScanner] = $this->scannersCountingHashes();

        foreach (range(1, 2) as $ignored) {
            $this->resetStaticState();

            $next = new CveClient();
            (new RequestTrigger($this->app['cache']->store('array'), $composerScanner, $npmScanner, $next, $this->settledPath))->maybeTrigger();

            $next->assertRequestsSent(0);
        }

        $this->assertSame(1, $composerScanner->hashes);
    }

    #[Test]
    public function a_disk_that_cannot_be_written_leaves_the_lockfiles_hashed_as_before()
    {
        $this->settledPath = '/dev/null/larabug/cve-settled';
        $this->ageLockfiles();

        $first = new CveClient();
        $this->trigger($first)->maybeTrigger();
        $first->assertRequestsSent(1);

        $this->resetStaticState();

        [$composerScanner, $npmScanner] = $this->scannersCountingHashes();

        $second = new CveClient();
        (new RequestTrigger($this->app['cache']->store('array'), $composerScanner, $npmScanner, $second, $this->settledPath))->maybeTrigger();

        $second->assertRequestsSent(0);
        $this->assertSame(1, $composerScanner->hashes);
    }

    #[Test]
    public function the_settled_mark_goes_into_the_applications_storage_by_default()
    {
        $this->ageLockfiles();

        $path = storage_path('larabug/cve-settled');
        @unlink($path);

        (new RequestTrigger(
            $this->app['cache']->store('array'),
            new ComposerLockScanner(),
            new NpmLockScanner($this->npmDirectory),
            new CveClient(),
        ))->maybeTrigger();

        $this->assertFileExists($path);

        unlink($path);
    }
}
