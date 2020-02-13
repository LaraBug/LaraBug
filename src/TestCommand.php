<?php


namespace LaraBug;


use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Command;

class TestCommand extends Command
{
    protected $signature = 'larabug:test';

    protected $description = 'Generate a test exception and send it to larabug';

    public function handle()
    {
        try {
            /** @var LaraBug $larabug */
            $larabug = app('larabug');

            if(config('larabug.login_key')) {
                $this->info('✓ [Larabug] Found login key');
            } else {
                $this->error('✗ [LaraBug] Could not find your login key, set this in your .env');
            }

            if(config('larabug.project_key')) {
                $this->info('✓ [Larabug] Found project key');
            } else {
                $this->error('✗ [LaraBug] Could not find your project key, set this in your .env');
            }

            if(in_array(config('app.env'), config('larabug.environments'))) {
                $this->info('✓ [Larabug] Correct environment found');
            } else {
                $this->error('✗ [LaraBug] Environment not allowed to send errors to LaraBug, set this in your config');
            }

            $response = $larabug->handle(
                $this->generateException()
            );

            if(isset($response->id)) {
                $this->info('✓ [LaraBug] Sent exception to LaraBug with ID: ' . $response->id);
            } elseif(is_null($response)) {
                $this->info('✓ [LaraBug] Sent exception to LaraBug!');
            } else {
                $this->error('✗ [LaraBug] Failed to send exception to LaraBug');
            }
        } catch(\Exception $ex) {
            $this->error("✗ [LaraBug] {$ex->getMessage()}");
        }
    }

    public function generateException(): ?Exception
    {
        try {
            throw new Exception('This is a test exception from the LaraBug console');
        } catch (Exception $ex) {
            return $ex;
        }
    }
}
