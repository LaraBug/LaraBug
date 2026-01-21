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

    'dsn' => env('LB_DSN', ''),

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
        | Enable or disable job monitoring
        */
        'enabled' => env('LB_JOBS_ENABLED', false),

        /*
        | Track all jobs (true) or only failed jobs (false)
        | Default: false (only failures) to minimize API usage
        */
        'track_all_jobs' => env('LB_JOBS_TRACK_ALL', false),

        /*
        | Ignore specific job classes
        | Example: [\App\Jobs\UnimportantJob::class]
        */
        'ignore_jobs' => [],

        /*
        | Only track jobs on specific queues (empty = all queues)
        | Example: ['high-priority', 'emails']
        */
        'only_queues' => [],

        /*
        | Ignore jobs on specific queues
        | Example: ['low-priority']
        */
        'ignore_queues' => [],

        /*
        | Maximum payload size in bytes (payloads larger will be truncated)
        */
        'max_payload_size' => env('LB_JOBS_MAX_PAYLOAD_SIZE', 10000),
    ],

];
