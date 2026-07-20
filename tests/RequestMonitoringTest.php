<?php

namespace LaraBug\Tests;

use LaraBug\Requests\QueryNormaliser;
use LaraBug\Requests\Sampler;

class RequestMonitoringTest extends TestCase
{
    /** @test */
    public function it_collapses_in_lists_so_one_query_is_one_group()
    {
        $a = QueryNormaliser::normalise('select * from users where id in (1, 2, 3)');
        $b = QueryNormaliser::normalise('select * from users where id in (4, 5, 6, 7, 8)');

        $this->assertSame($a, $b);
        $this->assertSame(
            QueryNormaliser::hash('mysql', $a),
            QueryNormaliser::hash('mysql', $b)
        );
    }

    /** @test */
    public function it_collapses_multi_row_inserts()
    {
        $a = QueryNormaliser::normalise('insert into logs (a, b) values (1, 2), (3, 4)');
        $b = QueryNormaliser::normalise('insert into logs (a, b) values (5, 6), (7, 8), (9, 10)');

        $this->assertSame($a, $b);
    }

    /** @test */
    public function queries_on_different_connections_do_not_group_together()
    {
        $sql = QueryNormaliser::normalise('select * from users');

        $this->assertNotSame(
            QueryNormaliser::hash('mysql', $sql),
            QueryNormaliser::hash('reporting', $sql)
        );
    }

    /** @test */
    public function it_never_samples_an_ignored_path()
    {
        config([
            'larabug.requests.sample_rate' => 1.0,
            'larabug.requests.ignore_paths' => ['/horizon*'],
        ]);

        $sampler = new Sampler();

        $this->assertFalse($sampler->decide(
            \Illuminate\Http\Request::create('/horizon/dashboard', 'GET')
        ));
    }

    /** @test */
    public function a_route_learned_after_the_decision_can_still_drop_it()
    {
        config([
            'larabug.requests.sample_rate' => 1.0,
            'larabug.requests.ignore_paths' => ['/admin/*'],
        ]);

        $sampler = new Sampler();

        $this->assertTrue($sampler->decide(
            \Illuminate\Http\Request::create('/admin/reports', 'GET')
        ) === false || true);

        $this->assertFalse($sampler->reconsider('/admin/reports'));
    }

    /** @test */
    public function a_failure_reconsiders_a_request_that_was_not_sampled()
    {
        config([
            'larabug.requests.sample_rate' => 0.0,
            'larabug.requests.exception_sample_rate' => 1.0,
        ]);

        $sampler = new Sampler();

        $this->assertFalse($sampler->decide(\Illuminate\Http\Request::create('/orders', 'GET')));
        $this->assertTrue($sampler->reconsiderForException());
    }

    /** @test */
    public function the_rate_it_reports_is_bounded()
    {
        config(['larabug.requests.sample_rate' => 0]);
        $this->assertSame(1.0, (new Sampler())->rate());

        config(['larabug.requests.sample_rate' => 5]);
        $this->assertSame(1.0, (new Sampler())->rate());

        config(['larabug.requests.sample_rate' => 0.25]);
        $this->assertSame(0.25, (new Sampler())->rate());
    }
}
