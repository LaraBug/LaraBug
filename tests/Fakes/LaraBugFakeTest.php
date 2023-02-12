<?php

declare(strict_types=1);

namespace LaraBug\Tests\Fakes;

use LaraBug\Tests\TestCase;
use LaraBug\Facade as LarabugFacade;

class LaraBugFakeTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        LarabugFacade::fake();

        $this->app['config']['logging.channels.larabug'] = ['driver' => 'larabug'];
        $this->app['config']['logging.default'] = 'larabug';
        $this->app['config']['larabug.environments'] = ['testing'];
    }

    /** @test */
    public function it_will_sent_exception_to_larabug_if_exception_is_thrown()
    {
        $this->app['router']->get('/exception', function () {
            throw new \Exception('Exception');
        });

        $this->get('/exception');

        LarabugFacade::assertSent(\Exception::class);

        LarabugFacade::assertSent(\Exception::class, function (\Throwable $throwable) {
            $this->assertSame('Exception', $throwable->getMessage());

            return true;
        });

        LarabugFacade::assertNotSent(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    }

    /** @test */
    public function it_will_sent_nothing_to_larabug_if_no_exceptions_thrown()
    {
        LarabugFacade::fake();

        $this->app['router']->get('/nothing', function () {
            //
        });

        $this->get('/nothing');

        LarabugFacade::assertNothingSent();
    }
}
