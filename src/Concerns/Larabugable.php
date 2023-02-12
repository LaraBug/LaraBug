<?php

declare(strict_types=1);

namespace LaraBug\Concerns;

interface Larabugable
{
    public function toLarabug(): array;
}
