<?php

declare(strict_types=1);

namespace LaraBug\Tests;

use LaraBug\LaraBug;
use LaraBug\Tests\Mocks\LaraBugClient;
use Illuminate\Foundation\Auth\User as AuthUser;

class UserTest extends TestCase
{
    /** @var Mocks\LaraBugClient */
    protected $client;

    /** @var LaraBug */
    protected $laraBug;

    public function setUp(): void
    {
        parent::setUp();

        $this->laraBug = new LaraBug($this->client = new LaraBugClient(
            'login_key',
            'project_key'
        ));
    }

    /** @test */
    public function it_return_custom_user()
    {
        $this->actingAs((new CustomerUser())->forceFill([
            'id' => 1,
            'username' => 'username',
            'password' => 'password',
            'email' => 'email',
        ]));

        $this->assertSame(['id' => 1, 'username' => 'username', 'password' => 'password', 'email' => 'email'], $this->laraBug->getUser());
    }

    /** @test */
    public function it_return_custom_user_with_to_larabug()
    {
        $this->actingAs((new CustomerUserWithToLarabug())->forceFill([
            'id' => 1,
            'username' => 'username',
            'password' => 'password',
            'email' => 'email',
        ]));

        $this->assertSame(['username' => 'username', 'email' => 'email'], $this->laraBug->getUser());
    }

    /** @test */
    public function it_returns_nothing_for_ghost()
    {
        $this->assertSame(null, $this->laraBug->getUser());
    }
}

class CustomerUser extends AuthUser
{
    protected $guarded = [];
}

class CustomerUserWithToLarabug extends CustomerUser implements \LaraBug\Concerns\Larabugable
{
    public function toLarabug()
    {
        return [
            'username' => $this->username,
            'email' => $this->email,
        ];
    }
}
