<?php

namespace LaraBug\Tests;

use LaraBug\ServiceProvider;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Routing\RouteCollection;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

class BootTest extends TestCase
{
    #[Test]
    public function booting_resolves_nothing_a_request_may_never_use()
    {
        $this->assertFalse($this->app->resolved(Schedule::class));
        $this->assertFalse($this->app->resolved('view'));
        $this->assertFalse($this->app->resolved('blade.compiler'));
    }

    #[Test]
    public function the_commands_are_scheduled_once_the_schedule_is_asked_for()
    {
        $commands = array_map(
            fn (Event $event) => $event->command,
            $this->app->make(Schedule::class)->events()
        );

        foreach (['larabug:queue-depth', 'larabug:flush', 'larabug:scan'] as $command) {
            $this->assertCount(
                1,
                array_filter($commands, fn (?string $scheduled) => str_contains((string) $scheduled, $command)),
                "{$command} is scheduled once"
            );
        }
    }

    #[Test]
    public function the_blade_directive_is_there_once_blade_is_used()
    {
        $this->assertStringContainsString(
            'larabug::larabug-js-client',
            Blade::compileString('@larabugJavaScriptClient')
        );
    }

    #[Test]
    public function the_views_are_found_once_a_view_is_asked_for()
    {
        $this->assertTrue($this->app['view']->exists('larabug::larabug-js-client'));
    }

    #[Test]
    public function the_report_route_is_registered()
    {
        $this->assertCount(1, $this->reportRoutes());
    }

    #[Test]
    public function the_report_route_is_left_to_the_route_cache_when_there_is_one()
    {
        $provider = new class ($this->app) extends ServiceProvider {
            public function mapRoutes(): void
            {
                $this->mapLaraBugApiRoutes();
            }
        };

        $this->app['router']->setRoutes(new RouteCollection());

        $provider->mapRoutes();

        $this->assertCount(1, $this->reportRoutes());

        $this->app['router']->setRoutes(new RouteCollection());

        $cache = $this->app->getCachedRoutesPath();

        $this->assertFileDoesNotExist($cache);

        touch($cache);

        // Newer Laravel versions remember the answer for the life of the application.
        $this->app->instance('routes.cached', true);

        try {
            $this->assertTrue($this->app->routesAreCached());

            $provider->mapRoutes();

            $this->assertCount(0, $this->reportRoutes());
        } finally {
            unlink($cache);
        }
    }

    /**
     * @return array<int, Route>
     */
    protected function reportRoutes(): array
    {
        return array_values(array_filter(
            $this->app['router']->getRoutes()->getRoutes(),
            fn (Route $route) => $route->uri() === 'larabug-api/javascript-report'
        ));
    }
}
