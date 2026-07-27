<?php

namespace LaraBug\Tests\Mocks;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Assert;

class LaraBugClient extends \LaraBug\Http\Client
{
    public const RESPONSE_ID = 'test';

    protected array $requests = [];

    public function report($exception): Response
    {
        $this->requests[] = $exception;

        return new Response(200, [], json_encode(['id' => self::RESPONSE_ID]));
    }

    public function requests(): array
    {
        return $this->requests;
    }

    public function assertRequestsSent(int $expectedCount): void
    {
        Assert::assertCount($expectedCount, $this->requests);
    }
}
