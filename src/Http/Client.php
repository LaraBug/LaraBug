<?php

namespace LaraBug\Http;

use Exception;
use GuzzleHttp\ClientInterface;
use Illuminate\Support\Facades\Http;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Client\PendingRequest;

class Client
{
    /** @var ClientInterface|null */
    protected $client;

    /** @var string */
    protected $login;

    /** @var string */
    protected $project;

    /**
     * @param string $login
     * @param string $project
     * @param ClientInterface|null $client
     */
    public function __construct(string $login, string $project, ClientInterface $client = null)
    {
        $this->login = $login;
        $this->project = $project;
        $this->client = $client;
    }

    /**
     * @param array $exception
     * @return \GuzzleHttp\Promise\PromiseInterface|\Illuminate\Http\Client\Response|\Psr\Http\Message\ResponseInterface|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function report($exception)
    {
        try {
            return $this->getGuzzleHttpClient()
                ->withToken($this->login)
                ->asJson()
                ->acceptJson()
                ->withUserAgent('LaraBug-Package')
                ->when(
                    !config('larabug.verify_ssl'),
                    function ($client) {
                        /** @var \Illuminate\Http\Client\PendingRequest|\GuzzleHttp\Client $client */
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

    /**
     * @return static
     */
    public function setGuzzleHttpClient(ClientInterface $client): self
    {
        $this->client = Http::timeout(15)->setClient($client)->buildClient();

        return $this;
    }
}
