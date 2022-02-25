<?php

namespace LaraBug\Commands;

use Exception;
use Illuminate\Console\Command;

class TestCommand extends Command
{
    protected $signature = 'larabug:test {exception?}';

    protected $description = 'Generate a test exception and send it to larabug';

    public function handle()
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

            if (in_array(config('app.env'), config('larabug.environments'))) {
                $this->info('✓ [Larabug] Correct environment found (' . config('app.env') . ')');
            } else {
                $this->error('✗ [LaraBug] Environment (' . config('app.env') . ') not allowed to send errors to LaraBug, set this in your config');
                $this->info('More information about environment configuration: https://www.larabug.com/docs/how-to-use/installation');
            }

            $response = $laraBug->handle(
                $this->generateException()
            );

            if (isset($response->id)) {
                $this->info('✓ [LaraBug] Sent exception to LaraBug with ID: '.$response->id);
            } elseif (is_null($response)) {
                $this->info('✓ [LaraBug] Sent exception to LaraBug!');
            } else {
                $this->error('✗ [LaraBug] Failed to send exception to LaraBug');
            }
        } catch (\Exception $ex) {
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
