## LaraBug

- LaraBug is this application's error tracking and monitoring service. The `larabug/larabug` package reports exceptions, log lines, queue jobs, scheduled tasks, artisan commands, HTTP requests, Livewire component activity and `composer.lock` CVE findings to it.
- Everything the package does is configured in `config/larabug.php` and driven by `LB_*` environment variables. Read that file before changing behaviour: almost every knob is a documented key there, and each one explains what it costs.
- Exceptions report themselves while `larabug.register_exception_handler` is true. Never also wire LaraBug into the application's own exception handler, that reports every exception twice.
- Livewire monitoring is on by default and costs nothing when Livewire is not installed. It names the component an update addressed, since every Livewire update posts to the same endpoint; its lifecycle timings need `larabug.requests.track_requests` as well.
- Every stream is on by default: exceptions, queue jobs, CVE scanning, logs (deprecations included), HTTP requests (sampled at a tenth), artisan commands, scheduled tasks and Livewire. Each one spends the account's event quota, so prefer a sample rate or log level over switching one off, and do not raise a sample rate unless you were asked to. Query plans for slow SELECTs (`LB_QUERY_EXPLAIN`) are the one opt-in, because they run LaraBug's own EXPLAIN against the database.
- IMPORTANT: activate the `larabug-development` skill when configuring the package, filtering what it sends, or reporting to LaraBug from application code.
- IMPORTANT: activate the `larabug-issue-triage` skill when investigating an exception, failing job or vulnerability that LaraBug may already have recorded.
