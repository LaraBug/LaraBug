<?php

declare(strict_types=1);

use LaraBug\LaraBug;

use function Pest\Laravel\actingAs;

use LaraBug\Tests\Mocks\LaraBugClient;
use LaraBug\Tests\Support\CustomerUser;

use function PHPUnit\Framework\assertSame;

use LaraBug\Tests\Support\CustomerUserWithToLarabug;

beforeEach(function () {
    $this->laraBug = new LaraBug($this->client = new LaraBugClient(
        'login_key',
        'project_key'
    ));
});

it('return_custom_user', function () {
    actingAs((new CustomerUser())->forceFill([
        'id' => 1,
        'username' => 'username',
        'password' => 'password',
        'email' => 'email',
    ]));

    assertSame(
        [
        'id' => 1,
        'username' => 'username',
        'password' => 'password',
        'email' => 'email'],
        $this->laraBug->getUser()
    );
});

it('return_custom_user_with_to_larabug', function () {
    actingAs((new CustomerUserWithToLarabug())->forceFill([
        'id' => 1,
        'username' => 'username',
        'password' => 'password',
        'email' => 'email',
    ]));

    assertSame(
        ['username' => 'username',
            'email' => 'email'],
        $this->laraBug->getUser()
    );
});

it('returns_nothing_for_ghost', function () {
    assertSame(null, $this->laraBug->getUser());
});
