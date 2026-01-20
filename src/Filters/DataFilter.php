<?php

namespace LaraBug\Filters;

use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;

class DataFilter
{
    protected array $blacklist;

    protected int $maxSize;

    public function __construct(array $blacklist = [], int $maxSize = 10000)
    {
        // Normalize blacklist to lowercase for case-insensitive matching
        $this->blacklist = array_map(function ($item) {
            return strtolower($item);
        }, $blacklist);

        $this->maxSize = $maxSize;
    }

    /**
     * Filter variables/arrays recursively
     */
    public function filterVariables($variables): array
    {
        if (!is_array($variables)) {
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
     * Filter payload data (for jobs)
     */
    public function filterPayload(array $payload): array
    {
        $filtered = $this->filterRecursive($payload);

        return $this->truncateIfNeeded($filtered);
    }

    /**
     * Filter parameters (combine variable filtering + parameter value filtering)
     * This is the main method to use for request parameters
     */
    public function filterParameters(array $parameters): array
    {
        // First filter out uploaded files
        $filtered = $this->filterParameterValues($parameters);
        
        // Then filter sensitive keys
        return $this->filterVariables($filtered);
    }

    /**
     * Filter parameter values (remove uploaded files)
     */
    public function filterParameterValues(array $parameters): array
    {
        return collect($parameters)->map(function ($value) {
            if ($this->shouldParameterValueBeFiltered($value)) {
                return '...';
            }
            return $value;
        })->toArray();
    }

    /**
     * Recursively filter data
     */
    protected function filterRecursive($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        $filtered = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->shouldFilter($key)) {
                $filtered[$key] = '[FILTERED]';
            } else {
                $filtered[$key] = $this->filterRecursive($value);
            }
        }

        return $filtered;
    }

    /**
     * Check if a key should be filtered based on blacklist
     */
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

    /**
     * Check if parameter value should be filtered (e.g., uploaded files)
     */
    public function shouldParameterValueBeFiltered($value): bool
    {
        return $value instanceof UploadedFile;
    }

    /**
     * Truncate payload if it exceeds max size
     */
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

    /**
     * Get the blacklist
     */
    public function getBlacklist(): array
    {
        return $this->blacklist;
    }
}
