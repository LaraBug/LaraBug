<?php

namespace LaraBug\Support;

use InvalidArgumentException;

class Dsn
{
    protected readonly string $loginKey;

    protected readonly string $projectKey;

    protected readonly string $server;

    public function __construct(string $dsn)
    {
        $this->parse($dsn);
    }

    /**
     * Parse a DSN string into components.
     * Format: https://login-key:project-key@host/path
     * Example: https://abc123:def456@www.larabug.com/api/log
     */
    protected function parse(string $dsn): void
    {
        $parsed = parse_url($dsn);

        if ($parsed === false || ! isset($parsed['scheme'], $parsed['user'], $parsed['pass'], $parsed['host'])) {
            throw new InvalidArgumentException(
                'Invalid DSN format. Expected format: https://login-key:project-key@host/path'
            );
        }

        $this->loginKey = $parsed['user'];
        $this->projectKey = $parsed['pass'];

        $this->server = sprintf(
            '%s://%s%s',
            $parsed['scheme'],
            $parsed['host'],
            $parsed['path'] ?? ''
        );
    }

    public function getLoginKey(): string
    {
        return $this->loginKey;
    }

    public function getProjectKey(): string
    {
        return $this->projectKey;
    }

    public function getServer(): string
    {
        return $this->server;
    }

    public static function make(string $dsn): static
    {
        return new static($dsn);
    }

    public static function isValid(string $dsn): bool
    {
        try {
            new static($dsn);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
