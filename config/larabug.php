<?php

return [

    /*
    |--------------------------------------------------------------------------
    | DSN (Data Source Name)
    |--------------------------------------------------------------------------
    |
    | You can use a single DSN string to configure LaraBug instead of
    | individual keys. Format: https://login-key:project-key@host/path
    | Example: https://abc123:def456@www.larabug.com/api/log
    |
    | If DSN is set, it will take precedence over individual keys below.
    |
    */

    'dsn' => env('LB_DSN'),

    /*
    |--------------------------------------------------------------------------
    | Login key
    |--------------------------------------------------------------------------
    |
    | This is your authorization key which you get from your profile.
    | Retrieve your key from https://www.larabug.com
    |
    */

    'login_key' => env('LB_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Project key
    |--------------------------------------------------------------------------
    |
    | This is your project key which you receive when creating a project
    | Retrieve your key from https://www.larabug.com
    |
    */

    'project_key' => env('LB_PROJECT_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Environment setting
    |--------------------------------------------------------------------------
    |
    | This setting determines if the exception should be send over or not.
    |
    */

    'environments' => [
        'production',
    ],

    /*
    |--------------------------------------------------------------------------
    | Project version
    |--------------------------------------------------------------------------
    |
    | Set the project version, default: null.
    | For git repository: shell_exec("git log -1 --pretty=format:'%h' --abbrev-commit")
    |
    */
    'project_version' => null,

    /*
    |--------------------------------------------------------------------------
    | Lines near exception
    |--------------------------------------------------------------------------
    |
    | How many lines to show near exception line. The more you specify the bigger
    | the displayed code will be. Max value can be 50, will be defaulted to
    | 12 if higher than 50 automatically.
    |
    */

    'lines_count' => 12,

    /*
    |--------------------------------------------------------------------------
    | Prevent duplicates
    |--------------------------------------------------------------------------
    |
    | Set the sleep time between duplicate exceptions. This value is in seconds, default: 60 seconds (1 minute)
    |
    */

    'sleep' => 60,

    /*
    |--------------------------------------------------------------------------
    | Skip exceptions
    |--------------------------------------------------------------------------
    |
    | List of exceptions to skip sending.
    |
    */

    'except' => [
        'Symfony\Component\HttpKernel\Exception\NotFoundHttpException',
    ],

    /*
    |--------------------------------------------------------------------------
    | Key filtering
    |--------------------------------------------------------------------------
    |
    | Filter out these variables before sending them to LaraBug
    |
    */

    'blacklist' => [
        '*authorization*',
        '*password*',
        '*token*',
        '*auth*',
        '*verification*',
        '*credit_card*',
        'cardToken', // mollie card token
        '*cvv*',
        '*iban*',
        '*name*',
        '*email*'
    ],

    /*
    |--------------------------------------------------------------------------
    | Release git hash
    |--------------------------------------------------------------------------
    |
    |
    */

    // 'release' => trim(exec('git --git-dir ' . base_path('.git') . ' log --pretty="%h" -n1 HEAD')),

    /*
    |--------------------------------------------------------------------------
    | Server setting
    |--------------------------------------------------------------------------
    |
    | This setting allows you to change the server.
    |
    */

    'server' => env('LB_SERVER', 'https://www.larabug.com/api/log'),

    /*
    |--------------------------------------------------------------------------
    | Verify SSL setting
    |--------------------------------------------------------------------------
    |
    | Enables / disables the SSL verification when sending exceptions to LaraBug
    | Never turn SSL verification off on production instances
    |
    */
    'verify_ssl' => env('LB_VERIFY_SSL', true),

    /*
    |--------------------------------------------------------------------------
    | Job Monitoring Configuration
    |--------------------------------------------------------------------------
    |
    | Configure queue job monitoring behavior.
    |
    */
    'jobs' => [
        /*
        | Enable or disable queue job tracking
        | Set to false via LB_TRACK_JOBS=false to completely disable
        | Default: true (tracking enabled)
        */
        'track_jobs' => env('LB_TRACK_JOBS', true),

        /*
        |--------------------------------------------------------------------------
        | Dynamic Batching (Automatic Load-Based)
        |--------------------------------------------------------------------------
        |
        | Batching automatically activates when job dispatch rate exceeds a 
        | threshold. During low traffic, events are sent immediately (no delay).
        | During high traffic, events are buffered and sent in batches.
        |
        | This provides the best of both worlds: zero delay for low traffic
        | and automatic protection during spikes.
        |
        */

        /*
        | Jobs per minute threshold to enable batching
        | When dispatch rate exceeds this, batching activates automatically
        | Default: 10 jobs/minute
        */
        'auto_batch_threshold' => env('LB_AUTO_BATCH_THRESHOLD', 10),

        /*
        | Jobs per minute threshold to disable batching
        | When rate drops below this (and cooldown passes), batching deactivates
        | Default: 5 jobs/minute
        */
        'auto_batch_disable_threshold' => env('LB_AUTO_BATCH_DISABLE', 5),

        /*
        | Cooldown period (in minutes) before disabling batching
        | After rate drops below threshold, wait this long before disabling
        | Prevents rapid enable/disable cycling
        | Default: 5 minutes
        */
        'auto_batch_cooldown' => env('LB_AUTO_BATCH_COOLDOWN', 5),

        /*
        | Number of events to buffer before auto-flushing to API
        | Higher values = fewer API calls but more memory usage
        | Recommended: 50-100 for most applications
        | Default: 50
        */
        'batch_size' => env('LB_BATCH_SIZE', 50),

        /*
        | Auto-flush interval in seconds
        | Events are sent when buffer is full OR after this many seconds
        | Ensures events aren't held indefinitely in low-traffic scenarios
        | Default: 30 seconds
        */
        'flush_interval' => env('LB_FLUSH_INTERVAL', 30),

        /*
        | Maximum retry attempts for failed API calls
        | Each retry uses exponential backoff (100ms, 200ms, 300ms)
        | Default: 3 retries
        */
        'max_retries' => env('LB_MAX_RETRIES', 3),

        /*
        |--------------------------------------------------------------------------
        | Smart Tracking (Reduce Volume)
        |--------------------------------------------------------------------------
        |
        | Control which job lifecycle events are tracked. Most users only care
        | about failures, so consider disabling processing/completed tracking
        | for high-volume queues.
        |
        */

        /*
        | Track when jobs START processing
        | Useful for debugging stuck jobs but doubles API calls
        | Default: false (most users don't need this)
        */
        'track_processing' => env('LB_TRACK_PROCESSING', false),

        /*
        | Track when jobs COMPLETE successfully
        | Useful for metrics but high volume for busy queues
        | Consider using sample_rate instead of disabling completely
        | Default: true
        */
        'track_completed' => env('LB_TRACK_COMPLETED', true),

        /*
        | Track when jobs FAIL
        | HIGHLY RECOMMENDED - this is the main value of LaraBug
        | Default: true (always track failures)
        */
        'track_failed' => env('LB_TRACK_FAILED', true),

        /*
        |--------------------------------------------------------------------------
        | Sampling Configuration
        |--------------------------------------------------------------------------
        |
        | For high-volume queues, you may not need 100% of successful completions.
        | Sampling tracks a percentage of completed jobs while still tracking
        | ALL failures at 100%.
        |
        */

        /*
        | Sample rate for successful job completions (0.0 - 1.0)
        | 1.0 = track 100% of successful jobs (default)
        | 0.5 = track 50% of successful jobs
        | 0.1 = track 10% of successful jobs
        | 0.0 = don't track successful jobs
        | 
        | Note: Failures are ALWAYS tracked at 100% regardless of this setting
        */
        'sample_rate' => env('LB_SAMPLE_RATE', 1.0),

        /*
        |--------------------------------------------------------------------------
        | Queue Filtering
        |--------------------------------------------------------------------------
        */

        /*
        | Only track jobs on specific queues (empty array = all queues)
        | Example: ['high-priority', 'emails']
        | Environment: LB_ONLY_QUEUES=high-priority,emails
        */
        'only_queues' => array_filter(explode(',', env('LB_ONLY_QUEUES', ''))),

        /*
        | Ignore jobs on specific queues
        | Example: ['low-priority', 'notifications']
        | Environment: LB_IGNORE_QUEUES=low-priority,notifications
        */
        'ignore_queues' => array_filter(explode(',', env('LB_IGNORE_QUEUES', ''))),

        /*
        | Ignore specific job classes
        | Example: [\App\Jobs\UnimportantJob::class]
        */
        'ignore_jobs' => [],

        /*
        |--------------------------------------------------------------------------
        | Advanced Settings
        |--------------------------------------------------------------------------
        */

        /*
        | Maximum payload size in bytes (payloads larger will be truncated)
        */
        'max_payload_size' => env('LB_JOBS_MAX_PAYLOAD_SIZE', 10000),

        /*
        | Report SDK errors back to LaraBug (useful for debugging)
        | Default: false
        */
        'report_sdk_errors' => env('LB_REPORT_SDK_ERRORS', false),

        /*
        | Report buffer flush errors back to LaraBug (useful for debugging)
        | Default: false
        */
        'report_buffer_errors' => env('LB_REPORT_BUFFER_ERRORS', false),
    ],
];
