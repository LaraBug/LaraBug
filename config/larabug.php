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
    | Register the exception handler
    |--------------------------------------------------------------------------
    |
    | Report exceptions automatically, so an application does not have to wire
    | LaraBug into its own exception handler. Disable this if you want to call
    | LaraBug::handle() yourself and decide per exception whether to send it.
    |
    */
    'register_exception_handler' => env('LB_REGISTER_EXCEPTION_HANDLER', true),

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
    /*
    |--------------------------------------------------------------------------
    | Queue Heartbeat
    |--------------------------------------------------------------------------
    |
    | Everything else this package sends is a report about something that
    | happened. None of it can tell a queue with no work from a queue with no
    | workers, since both send nothing at all.
    |
    | The heartbeat is the one message that says a worker exists. It runs from
    | the scheduler, so it needs `schedule:run` to be running; where Horizon is
    | installed it also carries its supervisor status, process counts and the
    | wait Horizon measures per queue.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Request Monitoring
    |--------------------------------------------------------------------------
    |
    | Records the HTTP requests this application serves: how long each stage of
    | the framework took, how many queries and cache calls it made, and which
    | statements ran.
    |
    | Off by default, and sampled at a tenth when switched on. A package that
    | starts reporting every request the moment it updates is a package that
    | spends its user's quota without being asked.
    |
    */
    'requests' => [
        /*
        | Enable or disable request monitoring
        | Set to true via LB_TRACK_REQUESTS=true to start recording
        | Default: false (opt-in)
        */
        'track_requests' => env('LB_TRACK_REQUESTS', false),

        /*
        | The fraction of requests recorded, 0.0 to 1.0
        | The server divides by this to report real throughput, so a tenth here
        | still reports the whole picture, just from a tenth of the evidence
        | Default: 0.1
        */
        'sample_rate' => env('LB_REQUEST_SAMPLE_RATE', 0.1),

        /*
        | The rate a failed request is reconsidered at
        | A request that threw is the one worth having, so an unsampled one
        | re-rolls at this rate rather than being lost to an earlier coin toss
        | Default: 1.0 (always keep a failure)
        */
        'exception_sample_rate' => env('LB_REQUEST_EXCEPTION_SAMPLE_RATE', 1.0),

        /*
        | Paths never recorded, matched with fnmatch against the route path
        | Checked again once the route is known, so patterns can name routes
        */
        'ignore_paths' => [
            '/horizon*',
            '/telescope*',
            '/_debugbar*',
            '/up',
            '/health*',
        ],

        /*
        | How many statements are kept per request
        | The query counter keeps counting past this: a request running ten
        | thousand queries is worth knowing about, and what makes it worth
        | knowing about is the number rather than the ten thousandth statement
        | Default: 100
        */
        'max_queries' => env('LB_REQUEST_MAX_QUERIES', 100),

        /*
        | How many outbound HTTP calls are kept per request
        | The outgoing counter keeps counting past this, the same as queries: a
        | request that fans out to a hundred services is worth knowing about, and
        | the number is what says so
        | Default: 50
        */
        'max_outgoing' => env('LB_REQUEST_MAX_OUTGOING', 50),

        /*
        | How many messages are kept per request
        | The mail counter keeps counting past this, the same as queries and
        | outgoing calls: a request that fans a newsletter out to a hundred
        | recipients is worth knowing about, and the number is what says so
        | Default: 50
        */
        'max_mail' => env('LB_REQUEST_MAX_MAIL', 50),

        /*
        | How many notifications are kept per request
        | The notification counter keeps counting past this, one entry per
        | channel, the same as mail: a request that notifies a thousand users is
        | worth knowing about, and the number is what says so
        | Default: 50
        */
        'max_notifications' => env('LB_REQUEST_MAX_NOTIFICATIONS', 50),

        /*
        | How many records are held before they are sent
        | A web process serves one request, so this mostly matters for Octane
        | and for the console
        | Default: 20
        */
        'batch_size' => env('LB_REQUEST_BATCH_SIZE', 20),

        'max_retries' => env('LB_REQUEST_MAX_RETRIES', 2),

        /*
        | Whether the client IP and the authenticated user id are recorded
        | Both are personal data; both are useful. Neither is a query string,
        | which is never recorded at all: reset tokens and signed url
        | signatures live there
        | Default: true
        */
        'capture_ip' => env('LB_REQUEST_CAPTURE_IP', true),
        'capture_user' => env('LB_REQUEST_CAPTURE_USER', true),

        /*
        | Whether a sent message's full recipient addresses are recorded
        | Off by default: the domains a request emailed are kept either way,
        | which is the diagnostic part, and the addresses are personal data with
        | no more business being stored than a query string's values. Turn this
        | on only where the recipients are yours to keep
        | Default: false
        */
        'capture_mail_recipients' => env('LB_REQUEST_CAPTURE_MAIL_RECIPIENTS', false),

        /*
        | Whether request headers are recorded
        | Headers are how you tell a browser from a crawler and a v1 client
        | from a v2 one, which is usually what a strange request turns out to
        | be. The names below are replaced with a marker rather than dropped:
        | knowing a request carried an Authorization header is worth having,
        | and knowing which token it carried is not
        | Default: true
        */
        'capture_headers' => env('LB_REQUEST_CAPTURE_HEADERS', true),

        'redact_headers' => [
            'authorization',
            'cookie',
            'set-cookie',
            'x-api-key',
            'x-csrf-token',
            'x-xsrf-token',
            'proxy-authorization',
        ],

        /*
        | Whether the request body is kept when the request failed
        | Off by default, and never on a success: a body is the most sensitive
        | thing a request carries, and it is only worth its risk on the handful
        | of requests that returned a 5xx and have to be reproduced
        | Default: false
        */
        'capture_payload_on_error' => env('LB_REQUEST_CAPTURE_PAYLOAD_ON_ERROR', false),

        /*
        | Fields replaced with a marker inside a captured body
        | Matched against the key at any depth, case-insensitively, and as a
        | substring: 'password' covers password_confirmation
        */
        'redact_fields' => [
            'password',
            'password_confirmation',
            'token',
            'secret',
            'card',
            'cvv',
            'iban',
        ],
    ],

    'heartbeat' => [
        /*
        | Enable or disable the heartbeat
        | Set to false via LB_HEARTBEAT=false to stop reporting worker state
        | Default: true
        */
        'enabled' => env('LB_HEARTBEAT', true),

        /*
        | How often the heartbeat is scheduled
        | One of: everyMinute, everyTwoMinutes, everyFiveMinutes, everyTenMinutes
        | Anything longer and a stopped worker goes unnoticed for that long
        | Default: everyMinute
        */
        'schedule' => env('LB_HEARTBEAT_SCHEDULE', 'everyMinute'),

        /*
        | Where the heartbeat is sent
        | Left null it follows `server` above, which is what a self-hosted
        | install wants; set it only when the two live apart
        | Default: null
        */
        'server' => env('LB_HEARTBEAT_SERVER'),

        /*
        | Which queues to measure when Horizon is not installed
        | Either names on the default connection, ['emails', 'default'], or
        | pairs, [['connection' => 'redis', 'queue' => 'emails']]
        | Left empty the default connection's own queue is used
        | Default: []
        */
        'queues' => [],
    ],

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

    /*
    |--------------------------------------------------------------------------
    | CVE / Dependency vulnerability scanning
    |--------------------------------------------------------------------------
    |
    | When enabled, the package periodically scans your composer.lock against
    | LaraBug's CVE database and surfaces findings as Issues on your project.
    | Independent of error and queue tracking — you can install LaraBug just
    | for vulnerability scanning if you want.
    |
    */
    'cve' => [
        /*
        | Master toggle. On by default — set LB_CVE_ENABLED=false to opt out.
        |
        | Turning this on alone does not start scanning: the project also has to
        | have CVE scanning enabled in LaraBug. Until it does, the server answers
        | 403 and the client backs off for an hour, so leaving this on costs at
        | most one request an hour.
        |
        | Only package names, versions and a hash of composer.lock ever leave the
        | app. No source, no paths, no env values.
        */
        'enabled' => env('LB_CVE_ENABLED', true),

        /*
        | How scans get triggered:
        |   'request'  — piggyback on incoming HTTP requests. Detects composer.lock
        |                changes ~immediately after deploy. Zero scheduler needed.
        |   'schedule' — only via the daily scheduled command. Requires schedule:run.
        |   'both'     — request-piggyback is the primary trigger; the scheduler
        |                acts as a safety net for apps with no inbound traffic.
        | Default: both.
        */
        'trigger' => env('LB_CVE_TRIGGER', 'both'),

        /*
        | Scheduler frequency. 'daily' | 'hourly' | 'twice-daily', or a custom
        | cron expression. Only used when trigger includes 'schedule' or 'both'.
        */
        'schedule' => env('LB_CVE_SCHEDULE', 'daily'),

        /*
        | Hours to wait before re-scanning an unchanged composer.lock when
        | triggered by request piggyback. Hash-change is detected immediately
        | regardless; this only applies to the heartbeat for stale stacks.
        */
        'request_throttle_hours' => env('LB_CVE_REQUEST_THROTTLE_HOURS', 24),

        /*
        | Include `packages-dev` from composer.lock in the scan.
        | Off by default — production deps are what matters in deployed apps.
        */
        'include_dev' => env('LB_CVE_INCLUDE_DEV', false),

        /*
        | Path to composer.lock. Defaults to base_path('composer.lock').
        */
        'lock_path' => env('LB_CVE_LOCK_PATH'),

        /*
        | Environment label sent alongside the scan (matches `app.env` by default).
        */
        'environment' => env('LB_CVE_ENVIRONMENT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logs
    |--------------------------------------------------------------------------
    |
    | Ships application log lines to LaraBug, so the lines leading up to an
    | error are there when you open it.
    |
    | Naming the channel in your logging stack is the opt-in; nothing is sent
    | until you do. Logs run at far higher volume than exceptions and count
    | against your plan, so opt in deliberately.
    |
    |   LOG_STACK=single,larabug-logs
    |
    | The package defines the `larabug-logs` channel itself, so there is
    | nothing to add to config/logging.php. Define a channel of that name
    | yourself if you want different settings; yours wins.
    |
    */

    'logs' => [
        /*
        | Kill switch. Leave it on: the channel decides whether logs are sent.
        | Set LB_LOGS_ENABLED=false to stop shipping without touching the
        | logging config, for instance while debugging a noisy deploy.
        */
        'enabled' => env('LB_LOGS_ENABLED', true),

        /*
        | Minimum severity to send, as a PSR-3 level name. Shipping 'debug'
        | from production is rarely what you want.
        */
        'level' => env('LB_LOGS_LEVEL', 'info'),

        /*
        | Lines held before a batch is sent. A request that logs fewer than
        | this sends one batch when it terminates.
        */
        'batch_size' => env('LB_LOGS_BATCH_SIZE', 50),

        /*
        | Retries on a 5xx. A refused batch (402, 403, 422) is never retried
        | and stops collection for the rest of the process.
        */
        'max_retries' => env('LB_LOGS_MAX_RETRIES', 3),

        /*
        | Ceiling on how many keys of one context or extra bag are sent. Log
        | context routinely holds whole models, and the rest is dropped with a
        | `_truncated` marker rather than shipping an object graph per line.
        */
        'max_context_keys' => env('LB_LOGS_MAX_CONTEXT_KEYS', 50),

        /*
        | Release identifier sent with every line, so a spike can be tied to a
        | deploy. A commit sha or a version tag.
        */
        'release' => env('LB_LOGS_RELEASE'),
    ],
];
