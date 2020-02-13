<?php


namespace LaraBug;


use Exception;
use Illuminate\Console\Command;

class TestCommand extends Command
{
    protected $name = 'larabug:test';

    protected $signature = 'larabug:test';

    protected $description = 'Generate a test exception and send it to larabug';

    public function handle()
    {
        try {
            /** @var LaraBug $larabug */
            $larabug = app('larabug');

            if(!config('larabug.login_key')) {
                $this->error('✗ [LaraBug] Could not find your login key, set this in your .env');
            } else {
                $this->info('✓ [Larabug] Found login key');
            }

            if(!config('larabug.project_key')) {
                $this->error('✗ [LaraBug] Could not find your project key, set this in your .env');
            } else {
                $this->info('✓ [Larabug] Found project key');
            }

            if(!in_array(app()->environment(), config('larabug.environments'))) {
                $this->error('✗ [LaraBug] Environment not allowed to send errors to Larabug, set this in your config');
            } else {
                $this->info('✓ [Larabug] Correct environment found');
            }

            $larabug->handle(
                $this->generateException()
            );
        } catch(\Exception $ex) {
            $this->error("✗ [LaraBug] {$ex->getMessage()}");
        }
    }

    public function generateException(): ?Exception
    {
        try {
            throw new Exception('This is a test exception from the larabug console');
        } catch (Exception $ex) {
            return $ex;
        }
    }
}
