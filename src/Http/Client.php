<?php

namespace LaraBug\Http;

use Cartalyst\Sentinel\Laravel\Facades\Sentinel;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class Client
{
    /** @var \GuzzleHttp\Client|null */
    protected $client;

    /** @var string */
    protected $login;

    /** @var string */
    protected $project;

    /**
     * @param string $login
     * @param string $project
     */
    public function __construct(string $login, string $project, \GuzzleHttp\Client $client = null)
    {
        $this->login = $login;
        $this->project = $project;
        $this->client = $client;
    }

    /**
     * @param $exception
     * @return \GuzzleHttp\Promise\PromiseInterface|\Psr\Http\Message\ResponseInterface|null
     */
    public function report($exception)
    {
        try {
            return $this->getGuzzleHttpClient()->request('POST', 'https://www.larabug.com/api/log', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->login
                ],
                'form_params' => [
                    'project' => $this->project,
                    'exception' => $exception,
                    'additional' => [],
                    'user' => $this->getUser(),
                ]
            ]);
        } catch(\GuzzleHttp\Exception\RequestException $e) {
            return $e->getResponse();
        }
    }

    /**
     * @return \GuzzleHttp\Client
     */
    public function getGuzzleHttpClient()
    {
        if (!isset($this->client)) {
            $this->client = new \GuzzleHttp\Client();
        }

        return $this->client;
    }

    /**
     * Get the authenticated user.
     *
     * Supported authentication systems: Laravel, Sentinel
     *
     * @return array|null
     */
    private function getUser()
    {
        if (function_exists('auth') && auth()->check()) {
            return auth()->user()->toArray();
        }

        if (class_exists(\Cartalyst\Sentinel\Sentinel::class) && $user = Sentinel::check()) {
            return $user->toArray();
        }

        return null;
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
