<?php

namespace LaraBug\Support;

class Dsn
{
    protected string $loginKey;
    protected string $projectKey;
    protected string $server;

    public function __construct(string $dsn)
    {
        $this->parse($dsn);
    }

    /**
     * Parse DSN string into components
     * Format: https://login-key:project-key@host/path
     * Example: https://abc123:def456@www.larabug.com/api/log
     */
    protected function parse(string $dsn): void
    {
        $parsed = parse_url($dsn);

        if ($parsed === false || !isset($parsed['scheme'], $parsed['user'], $parsed['pass'], $parsed['host'])) {
            throw new \InvalidArgumentException(
                'Invalid DSN format. Expected format: https://login-key:project-key@host/path'
            );
        }

        $this->loginKey = $parsed['user'];
        $this->projectKey = $parsed['pass'];

        // Reconstruct server URL
        $this->server = sprintf(
            '%s://%s%s',
            $parsed['scheme'],
            $parsed['host'],
            $parsed['path'] ?? ''
        );
    }

    /**
     * Get the login key
     */
    public function getLoginKey(): string
    {
        return $this->loginKey;
    }

    /**
     * Get the project key
     */
    public function getProjectKey(): string
    {
        return $this->projectKey;
    }

    /**
     * Get the server URL
     */
    public function getServer(): string
    {
        return $this->server;
    }

    /**
     * Create DSN instance from string
     */
    public static function make(string $dsn): self
    {
        return new static($dsn);
    }

    /**
     * Check if a string is a valid DSN format
     */
    public static function isValid(string $dsn): bool
    {
        try {
            new static($dsn);
            return true;
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }
}
