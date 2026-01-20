# Queue Job Monitoring

LaraBug now supports comprehensive queue job monitoring to track your Laravel jobs.

## Installation

The job monitoring feature is included in LaraBug v3.0+. No additional packages required.

## Configuration

### Enable Job Monitoring

Add to your `.env` file:

```env
LB_JOBS_ENABLED=true
```

### Configuration Options

Publish the config file if you haven't already:

```bash
php artisan vendor:publish --provider="LaraBug\ServiceProvider"
```

Configure in `config/larabug.php`:

```php
'jobs' => [
    // Enable/disable job monitoring
    'enabled' => env('LB_JOBS_ENABLED', false),

    // Track all jobs (true) or only failed jobs (false)
    'track_all_jobs' => env('LB_JOBS_TRACK_ALL', false),

    // Ignore specific job classes
    'ignore_jobs' => [
        // \App\Jobs\UnimportantJob::class,
    ],

    // Only track jobs on specific queues
    'only_queues' => [
        // 'high-priority', 'emails'
    ],

    // Ignore jobs on specific queues
    'ignore_queues' => [
        // 'low-priority'
    ],

    // Maximum payload size (bytes)
    'max_payload_size' => env('LB_JOBS_MAX_PAYLOAD_SIZE', 10000),
],
```

## Usage

### Default Behavior

By default, LaraBug only tracks **failed jobs** to minimize API usage.

```php
// This will only be tracked if it fails
ProcessPodcast::dispatch($podcast);
```

### Track All Jobs

Enable global tracking in `.env`:

```env
LB_JOBS_TRACK_ALL=true
```

Now all jobs (processing, completed, failed) will be tracked.

### Track Specific Jobs

You can selectively track specific jobs using the fluent `->track()` method:

```php
// Track this specific job (even if global tracking is disabled)
ProcessPodcast::dispatch($podcast)->track();

// Also works with queued jobs
ProcessPodcast::dispatch($podcast)
    ->onQueue('high-priority')
    ->track();

// Works with delayed jobs
ProcessPodcast::dispatch($podcast)
    ->delay(now()->addMinutes(5))
    ->track();
```

### Disable Tracking for Specific Jobs

```php
// Don't track this job (even if global tracking is enabled)
ProcessPodcast::dispatch($podcast)->track(false);
```

Or add to ignore list in config:

```php
'jobs' => [
    'ignore_jobs' => [
        \App\Jobs\UnimportantJob::class,
        \App\Jobs\DebugJob::class,
    ],
],
```

## What Gets Tracked

LaraBug tracks comprehensive information about your jobs:

- **Job identification**: Class name, queue, connection
- **Status**: pending, processing, completed, failed
- **Performance**: Duration (ms), memory usage
- **Attempts**: Current attempt, max tries, timeout
- **Payload**: Job data (with sensitive information filtered)
- **Failures**: Full exception details and stack trace

## Sensitive Data Filtering

Job payloads are automatically filtered to remove sensitive information:

```php
// Automatically filtered keys (from config/larabug.php blacklist):
- *password*
- *token*
- *api_key*
- *auth*
- *credit_card*
- *cvv*
- etc.
```

Example:

```php
// Job payload
ProcessPayment::dispatch([
    'amount' => 99.99,
    'credit_card' => '4111-1111-1111-1111', // Will be filtered
    'user_id' => 123,
]);

// What LaraBug receives:
[
    'amount' => 99.99,
    'credit_card' => '[FILTERED]',  // ✅ Automatically filtered
    'user_id' => 123,
]
```

## Examples

### Example 1: Track Important Jobs Only

```php
// .env
LB_JOBS_ENABLED=true
LB_JOBS_TRACK_ALL=false  // Only track failures by default

// In your code
ProcessPayment::dispatch($payment)->track();  // ✅ Always track payments
SendEmail::dispatch($email);  // ❌ Only track if it fails
```

### Example 2: Track Specific Queues

```php
// config/larabug.php
'jobs' => [
    'enabled' => true,
    'track_all_jobs' => true,
    'only_queues' => ['payments', 'emails', 'high-priority'],
],
```

### Example 3: Ignore Low Priority Queues

```php
// config/larabug.php
'jobs' => [
    'enabled' => true,
    'track_all_jobs' => true,
    'ignore_queues' => ['low-priority', 'cleanup'],
],
```

## Performance Considerations

Job monitoring is designed for **minimal overhead**:

- ✅ **Non-blocking**: Monitoring failures never break your jobs
- ✅ **Async**: Data is sent asynchronously to LaraBug API
- ✅ **Smart defaults**: Only track failures by default
- ✅ **Payload limits**: Large payloads are automatically truncated
- ✅ **Silent failures**: If monitoring fails, jobs continue normally

### Recommended Production Settings

```env
LB_JOBS_ENABLED=true
LB_JOBS_TRACK_ALL=false  # Only failures
LB_JOBS_MAX_PAYLOAD_SIZE=5000  # 5KB limit
```

## Viewing Job Data

Job monitoring data is available in your LaraBug dashboard:

1. Go to your project in LaraBug
2. Navigate to the "Jobs" section
3. View jobs grouped by class name
4. See execution counts, failure rates, and performance metrics
5. Click individual jobs to see full details

## API Payload Structure

When a job is tracked, LaraBug receives:

```json
{
  "type": "queue_job",
  "project": "your-project-key",
  "job": {
    "job_id": "abc123",
    "job_class": "App\\Jobs\\ProcessPodcast",
    "connection": "redis",
    "queue": "default",
    "status": "failed",
    "attempts": 3,
    "max_tries": 3,
    "timeout": 60,
    "duration_ms": 1234.56,
    "memory_usage": 12582912,
    "payload": { ... },
    "exception": {
      "class": "RuntimeException",
      "message": "Failed to process podcast",
      "file": "/app/Jobs/ProcessPodcast.php",
      "line": 42,
      "trace": "..."
    }
  }
}
```

## Troubleshooting

### Jobs not being tracked

1. Check `LB_JOBS_ENABLED=true` in `.env`
2. Ensure queue worker is running: `php artisan queue:work`
3. Check job is not in `ignore_jobs` list
4. Check queue is not in `ignore_queues` list
5. If `track_all_jobs=false`, only failures are tracked

### Too many jobs being tracked

1. Set `LB_JOBS_TRACK_ALL=false` to only track failures
2. Add less important jobs to `ignore_jobs`
3. Use `ignore_queues` to skip low-priority queues
4. Use `->track(false)` on specific job dispatches

### Sensitive data appearing

1. Add sensitive keys to `blacklist` in `config/larabug.php`
2. Keys are matched with wildcards: `*password*` matches `user_password`, `password_hash`, etc.

## Upgrade Guide

If upgrading from an earlier version of LaraBug:

1. Update your package: `composer update larabug/larabug`
2. Publish new config: `php artisan vendor:publish --provider="LaraBug\ServiceProvider" --force`
3. Add new env variables to `.env`:
   ```env
   LB_JOBS_ENABLED=true
   LB_JOBS_TRACK_ALL=false
   ```
4. Start tracking jobs!

## Support

Need help? Contact us at support@larabug.com or visit https://www.larabug.com
