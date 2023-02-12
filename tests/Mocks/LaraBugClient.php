<?php

declare(strict_types=1);

namespace LaraBug\Tests\Mocks;

use LaraBug\Http\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Assert;

class LaraBugClient extends Client
{
    public const RESPONSE_ID = 'test';

    protected array $requests = [];

    /**
     * @param array $exception
     */
    public function report(array $exception): Response
    {
        $this->requests[] = $exception;

        return new Response(200, [], json_encode(['id' => self::RESPONSE_ID]));
    }

    public function assertRequestsSent(int $expectedCount)
    {
        Assert::assertCount($expectedCount, $this->requests);
    }
}
