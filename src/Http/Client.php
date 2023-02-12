<?php

declare(strict_types=1);

namespace LaraBug\Http;

use GuzzleHttp\ClientInterface;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Exception\RequestException;

class Client
{
    public function __construct(
        protected string $login,
        protected string $project,
        protected ?ClientInterface $client = null
    ) {
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function report(array $exception): PromiseInterface|ResponseInterface|null
    {
        try {
            return $this->getGuzzleHttpClient()->request('POST', config('larabug.server'), [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->login,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => 'LaraBug-Package'
                ],
                'json' => array_merge([
                    'project' => $this->project,
                    'additional' => [],
                ], $exception),
                'verify' => config('larabug.verify_ssl'),
            ]);
        } catch (RequestException $e) {
            return $e->getResponse();
        } catch (\Exception) {
            return null;
        }
    }

    public function getGuzzleHttpClient(): \GuzzleHttp\Client|ClientInterface|null
    {
        if (! isset($this->client)) {
            $this->client = Http::timeout(15)->buildClient();
        }

        return $this->client;
    }

    public function setGuzzleHttpClient(ClientInterface $client): static
    {
        $this->client = $client;

        return $this;
    }
}
