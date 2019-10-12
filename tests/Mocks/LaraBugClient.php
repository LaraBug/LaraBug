<?php

namespace LaraBug\Tests\Mocks;

use PHPUnit\Framework\Assert;

class LaraBugClient extends \LaraBug\Http\Client
{
    /** @var array */
    protected $requests = [];

    /**
     * @param array $exception
     */
    public function report($exception): void
    {
        $this->requests[] = $exception;
    }

    /**
     * @param int $expectedCount
     */
    public function assertRequestsSent(int $expectedCount)
    {
        Assert::assertCount($expectedCount, $this->requests);
    }
}