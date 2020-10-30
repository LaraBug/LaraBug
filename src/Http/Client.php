<?php

namespace LaraBug\Http;

use GuzzleHttp\ClientInterface;

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
     * @return \GuzzleHttp\Promise\PromiseInterface|\Psr\Http\Message\ResponseInterface|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function report($exception)
    {
        try {
            return $this->getGuzzleHttpClient()->request('POST', 'https://www.larabug.com/api/log', [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->login,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'json' => array_merge([
                    'project' => $this->project,
                    'additional' => [],
                ], $exception),
            ]);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            return $e->getResponse();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @return \GuzzleHttp\Client
     */
    public function getGuzzleHttpClient()
    {
        if (! isset($this->client)) {
            $this->client = new \GuzzleHttp\Client([
                'timeout' => 15,
            ]);
        }

        return $this->client;
    }

    /**
     * @param ClientInterface $client
     * @return $this
     */
    public function setGuzzleHttpClient(ClientInterface $client)
    {
        $this->client = $client;

        return $this;
    }
}
