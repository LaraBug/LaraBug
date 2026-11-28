<?php

namespace LaraBug\Tests;

use LaraBug\Http\Client;
use LaraBug\Support\Dsn;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

class DsnTest extends TestCase
{
    #[Test]
    public function it_reads_the_keys_and_the_server_from_a_dsn()
    {
        $dsn = Dsn::make('https://login-key:project-key@www.larabug.com/api/log');

        $this->assertSame('login-key', $dsn->getLoginKey());
        $this->assertSame('project-key', $dsn->getProjectKey());
        $this->assertSame('https://www.larabug.com/api/log', $dsn->getServer());
    }

    #[Test]
    public function it_keeps_the_port_of_the_server()
    {
        $dsn = Dsn::make('http://login-key:project-key@127.0.0.1:9701/api/log');

        $this->assertSame('http://127.0.0.1:9701/api/log', $dsn->getServer());
    }

    #[Test]
    public function it_reads_a_dsn_without_a_path()
    {
        $dsn = Dsn::make('https://login-key:project-key@larabug.test:8443');

        $this->assertSame('https://larabug.test:8443', $dsn->getServer());
    }

    #[Test]
    public function it_refuses_a_dsn_without_keys()
    {
        $this->assertFalse(Dsn::isValid('https://www.larabug.com/api/log'));

        $this->expectException(InvalidArgumentException::class);

        Dsn::make('https://www.larabug.com/api/log');
    }

    #[Test]
    public function it_sends_to_the_port_a_configured_dsn_names()
    {
        $this->app['config']['larabug.dsn'] = 'http://login-key:project-key@127.0.0.1:9701/api/log';

        $this->app->forgetInstance(Client::class);
        $this->app->make(Client::class);

        $this->assertSame('http://127.0.0.1:9701/api/log', $this->app['config']['larabug.server']);
    }
}
