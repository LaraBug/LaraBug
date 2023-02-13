<?php

declare(strict_types=1);

namespace LaraBug\Http;

use Exception;
use GuzzleHttp\ClientInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Client\PendingRequest;

class Client
{
    public function __construct(
        protected string $login,
        protected string $project,
        protected ?ClientInterface $client = null
    ) {
    }

    public function report(array $exception): PromiseInterface|Response|ResponseInterface|null
    {
        try {
            return $this->getGuzzleHttpClient()
                ->withToken($this->login)
                ->asJson()
                ->acceptJson()
                ->withUserAgent('LaraBug-Package')
                ->when(
                    !config('larabug.verify_ssl'),
                    function (PendingRequest|\GuzzleHttp\Client $client) {
                        $client->withoutVerifying();
                    }
                )
                ->post(
                    config('larabug.server'),
                    array_merge(
                        [
                            'project' => $this->project,
                            'additional' => [],
                        ],
                        $exception
                    )
                );
        } catch (RequestException $exception) {
            report($exception);
            return $exception->getResponse();
        } catch (Exception $exception) {
            report($exception);
            return null;
        }
    }

    public function getGuzzleHttpClient(): PendingRequest
    {
        if ($this->client === null) {
            return Http::timeout(15);
        }

        return Http::timeout(15)->setClient($this->client);
    }

    public function setGuzzleHttpClient(ClientInterface $client): static
    {
        $this->client = Http::timeout(15)->setClient($client)->buildClient();

        return $this;
    }
}
