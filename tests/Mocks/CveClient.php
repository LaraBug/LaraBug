<?php

namespace LaraBug\Tests\Mocks;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Assert;

/**
 * Records what the CVE trigger sends and answers with whatever status and body
 * a test asks for, so the "only remember success" rule can be exercised.
 */
class CveClient extends \LaraBug\Http\Client
{
    /** @var array */
    public $requests = [];

    /** @var int */
    protected $status;

    /** @var string */
    protected $body;

    public function __construct(int $status = 202, string $body = '{"queued":true,"snapshot_id":"snap-1"}')
    {
        parent::__construct('login-key', 'project-key');

        $this->status = $status;
        $this->body = $body;
    }

    public function report($exception)
    {
        $this->requests[] = $exception;

        return new Response($this->status, [], $this->body);
    }

    public function assertRequestsSent(int $expectedCount)
    {
        Assert::assertCount($expectedCount, $this->requests);
    }

    /** @return array|null */
    public function lastRequest()
    {
        return $this->requests ? end($this->requests) : null;
    }
}
