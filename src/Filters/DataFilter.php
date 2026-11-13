<?php

namespace LaraBug\Filters;

use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;

class DataFilter
{
    /** @var array<int, string> */
    protected readonly array $blacklist;

    protected readonly int $maxSize;

    public function __construct(array $blacklist = [], int $maxSize = 10000)
    {
        // Lowercased once so matching is case-insensitive.
        $this->blacklist = array_map(strtolower(...), $blacklist);

        $this->maxSize = $maxSize;
    }

    public function filterVariables(mixed $variables): array
    {
        if (! is_array($variables)) {
            return [];
        }

        array_walk($variables, function ($val, $key) use (&$variables) {
            if (is_array($val)) {
                $variables[$key] = $this->filterVariables($val);
            }

            if (is_string($key) && $this->shouldFilter($key)) {
                $variables[$key] = '***';
            }
        });

        return $variables;
    }

    /**
     * Filter payload data (for jobs).
     */
    public function filterPayload(array $payload): array
    {
        $filtered = $this->filterRecursive($payload);

        return $this->truncateIfNeeded($filtered);
    }

    /**
     * The main entry point for request parameters: strips uploaded files
     * first, then sensitive keys.
     */
    public function filterParameters(array $parameters): array
    {
        $filtered = $this->filterParameterValues($parameters);

        return $this->filterVariables($filtered);
    }

    public function filterParameterValues(array $parameters): array
    {
        return collect($parameters)
            ->map(fn ($value) => $this->shouldParameterValueBeFiltered($value) ? '...' : $value)
            ->toArray();
    }

    protected function filterRecursive(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        $filtered = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->shouldFilter($key)) {
                $filtered[$key] = '[FILTERED]';

                continue;
            }

            $filtered[$key] = $this->filterRecursive($value);
        }

        return $filtered;
    }

    protected function shouldFilter(string $key): bool
    {
        $lowerKey = strtolower($key);

        foreach ($this->blacklist as $pattern) {
            // Support wildcard patterns like *password*
            if (Str::is($pattern, $lowerKey)) {
                return true;
            }
        }

        return false;
    }

    public function shouldParameterValueBeFiltered(mixed $value): bool
    {
        return $value instanceof UploadedFile;
    }

    protected function truncateIfNeeded(array $payload): array
    {
        $json = json_encode($payload);

        if (strlen($json) > $this->maxSize) {
            return [
                '_truncated' => true,
                '_original_size' => strlen($json),
                '_max_size' => $this->maxSize,
                '_message' => 'Payload was truncated because it exceeded size limit',
            ];
        }

        return $payload;
    }

    /** @return array<int, string> */
    public function getBlacklist(): array
    {
        return $this->blacklist;
    }
}
