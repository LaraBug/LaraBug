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
    public function __construct(string $login, string $project, ?ClientInterface $client = null)
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
                'allow_redirects' => [
                    'max' => 5,
                    'strict' => true,  // Preserve POST method on redirects
                    'referer' => true,
                    'protocols' => ['http', 'https'],
                    'track_redirects' => false
                ],
            ]);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            return $e->getResponse();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Report a batch of served HTTP requests.
     *
     * The same endpoint and the same envelope as everything else, told apart by
     * type. Each record carries the queries that request ran, inline, because
     * they are one execution: a request whose queries arrived separately could
     * have half of itself stored.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return \Psr\Http\Message\ResponseInterface|null
     */
    public function reportRequests(array $records)
    {
        try {
            return $this->getGuzzleHttpClient()->request('POST', config('larabug.server'), [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->login,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => 'LaraBug-Package',
                ],
                'json' => [
                    'type' => 'requests_batch',
                    'project' => $this->project,
                    'requests' => $records,
                    'count' => count($records),
                ],
                'verify' => config('larabug.verify_ssl'),
                // Shorter than a report's fifteen. This runs on shutdown while
                // the worker is still held, so our slowness is their capacity.
                'timeout' => 5,
            ]);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            return $e->getResponse();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     */
    public function reportCommands(array $records)
    {
        try {
            return $this->getGuzzleHttpClient()->request('POST', config('larabug.server'), [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->login,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => 'LaraBug-Package',
                ],
                'json' => [
                    'type' => 'commands_batch',
                    'project' => $this->project,
                    'commands' => $records,
                    'count' => count($records),
                ],
                'verify' => config('larabug.verify_ssl'),
                'timeout' => 5,
            ]);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            return $e->getResponse();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     */
    public function reportScheduledTasks(array $records)
    {
        try {
            return $this->getGuzzleHttpClient()->request('POST', config('larabug.server'), [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->login,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => 'LaraBug-Package',
                ],
                'json' => [
                    'type' => 'scheduled_tasks_batch',
                    'project' => $this->project,
                    'scheduled_tasks' => $records,
                    'count' => count($records),
                ],
                'verify' => config('larabug.verify_ssl'),
                'timeout' => 5,
            ]);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            return $e->getResponse();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Report that this app's workers are alive.
     *
     * Its own endpoint rather than a kind of report: this arrives on a schedule
     * whether or not anything happened, and the thing it proves is that the
     * sender is running at all.
     *
     * @param  array  $payload
     * @return \Psr\Http\Message\ResponseInterface|null
     */
    public function heartbeat(array $payload)
    {
        try {
            return $this->getGuzzleHttpClient()->request('POST', $this->heartbeatUrl(), [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->login,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => 'LaraBug-Package',
                ],
                'json' => array_merge(['project' => $this->project], $payload),
                'verify' => config('larabug.verify_ssl'),
                // Shorter than a report's: this runs every minute from the
                // scheduler, and a broker that has gone away should not hold the
                // schedule open behind it.
                'timeout' => 5,
            ]);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            return $e->getResponse();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Follows the reporting server unless told otherwise, so a self-hosted
     * install does not have to configure the same host twice.
     *
     * @return string
     */
    protected function heartbeatUrl(): string
    {
        $configured = config('larabug.heartbeat.server');

        if ($configured) {
            return $configured;
        }

        $server = (string) config('larabug.server');

        if (substr($server, -8) === '/api/log') {
            return substr($server, 0, -8).'/api/heartbeat';
        }

        return rtrim($server, '/').'/heartbeat';
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
