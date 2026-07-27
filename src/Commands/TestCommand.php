<?php

namespace LaraBug\Commands;

use Exception;
use LaraBug\LaraBug;
use Illuminate\Console\Command;

class TestCommand extends Command
{
    protected $signature = 'larabug:test {exception?}';

    protected $description = 'Generate a test exception and send it to larabug';

    public function handle(): void
    {
        try {
            /** @var LaraBug $laraBug */
            $laraBug = app('larabug');

            if (config('larabug.login_key')) {
                $this->info('✓ [Larabug] Found login key');
            } else {
                $this->error('✗ [LaraBug] Could not find your login key, set this in your .env');
            }

            if (config('larabug.project_key')) {
                $this->info('✓ [Larabug] Found project key');
            } else {
                $this->error('✗ [LaraBug] Could not find your project key, set this in your .env');
                $this->info('More information on setting your project key: https://www.larabug.com/docs/how-to-use/installation');
            }

            $environment = config('app.env');

            if (in_array($environment, config('larabug.environments'))) {
                $this->info("✓ [Larabug] Correct environment found ({$environment})");
            } else {
                $this->error("✗ [LaraBug] Environment ({$environment}) not allowed to send errors to LaraBug, set this in your config");
                $this->info('More information about environment configuration: https://www.larabug.com/docs/how-to-use/installation');
            }

            $response = $laraBug->handle(
                $this->generateException()
            );

            if (isset($response->id)) {
                $this->info("✓ [LaraBug] Sent exception to LaraBug with ID: {$response->id}");
            } elseif ($response === null) {
                $this->info('✓ [LaraBug] Sent exception to LaraBug!');
            } else {
                $this->error('✗ [LaraBug] Failed to send exception to LaraBug');
            }
        } catch (Exception $ex) {
            $this->error("✗ [LaraBug] {$ex->getMessage()}");
        }
    }

    public function generateException(): ?Exception
    {
        try {
            throw new Exception($this->argument('exception') ?? 'This is a test exception from the LaraBug console');
        } catch (Exception $ex) {
            return $ex;
        }
    }
}
