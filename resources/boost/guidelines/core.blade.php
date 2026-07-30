## LaraBug

- LaraBug is this application's error tracking and monitoring service. The `larabug/larabug` package reports exceptions, log lines, queue jobs, scheduled tasks, artisan commands, HTTP requests and `composer.lock` CVE findings to it.
- Everything the package does is configured in `config/larabug.php` and driven by `LB_*` environment variables. Read that file before changing behaviour: almost every knob is a documented key there, and each one explains what it costs.
- Exceptions report themselves while `larabug.register_exception_handler` is true. Never also wire LaraBug into the application's own exception handler, that reports every exception twice.
- Only exceptions, queue jobs and CVE scanning are on by default. Request, command, scheduled task and log reporting are opt-in because each one spends the account's event quota. Do not turn one on unless you were asked to.
- IMPORTANT: activate the `larabug-development` skill when configuring the package, filtering what it sends, or reporting to LaraBug from application code.
- IMPORTANT: activate the `larabug-issue-triage` skill when investigating an exception, failing job or vulnerability that LaraBug may already have recorded.
