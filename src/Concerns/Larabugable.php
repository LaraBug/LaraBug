<?php

declare(strict_types=1);

namespace LaraBug\Concerns;

interface Larabugable
{
    /**
     * @return array
     */
    public function toLarabug();
}
