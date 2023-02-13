<?php

declare(strict_types=1);

namespace LaraBug\Tests\Support;

use Illuminate\Foundation\Auth\User as AuthUser;

class CustomerUser extends AuthUser
{
    protected $guarded = [];
}
