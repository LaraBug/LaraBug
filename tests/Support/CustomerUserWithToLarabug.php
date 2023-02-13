<?php

declare(strict_types=1);

namespace LaraBug\Tests\Support;

use LaraBug\Concerns\Larabugable;

class CustomerUserWithToLarabug extends CustomerUser implements Larabugable
{
    public function toLarabug(): array
    {
        return [
            'username' => $this->username,
            'email' => $this->email,
        ];
    }
}
