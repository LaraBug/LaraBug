---
name: larabug-development
description: Use when configuring the LaraBug package, controlling what it reports, or sending data to LaraBug from application code. Covers config/larabug.php and the LB_* environment variables, filtering sensitive data, manual exception reporting, per-exception context, queue job tracking, trace propagation between applications, Livewire component tracking, log shipping, CVE scanning, the JavaScript client, and testing with the LaraBug fake.
metadata:
  author: LaraBug
  tags:
    - error-tracking
    - monitoring
    - observability
    - larabug
---

# LaraBug Development

## When to use this skill

Use it when working on anything the `larabug/larabug` package does: configuring it, deciding what it reports, filtering what leaves the application, reporting from code, or writing tests around it.

To investigate an error that LaraBug has already recorded, use the `larabug-issue-triage` skill instead.

## Configuration

Everything lives in `config/larabug.php`, published with:

```
php artisan vendor:publish --provider="LaraBug\ServiceProvider"
```

Credentials come from either a DSN or a pair of keys. The DSN wins when both are set.

```
LB_DSN=https://login-key:project-key@www.larabug.com/api/log

# or
LB_KEY=your-login-key
LB_PROJECT_KEY=your-project-key
```

Read the config file before changing behaviour. Every key documents what it does and what it costs, and the answer to "how do I make it report X" is almost always a key that already exists.

### Reporting is scoped to environments

`larabug.environments` defaults to `['production']`. Nothing is sent from any other environment, which is the usual reason a developer reports that "LaraBug is not working" locally. Add the environment deliberately rather than removing the check, and remember an empty array disables reporting everywhere.

### What is on by default

| Feature | Default | Switch |
|---|---|---|
| Exceptions | on | `larabug.register_exception_handler` |
| Queue jobs | on | `LB_TRACK_JOBS` |
| Queue depth | follows queue jobs | `LB_QUEUE_DEPTH`, needs `schedule:run` |
| Livewire components | on | `LB_TRACK_LIVEWIRE` |
| CVE scanning | on | `LB_CVE_ENABLED` |
| Logs | on, from `info` up | `LB_LOGS_ENABLED`, `LB_LOGS_LEVEL` |
| Deprecations | follows logs | `LB_LOG_DEPRECATIONS` |
| HTTP requests | on, sampled at a tenth | `LB_TRACK_REQUESTS`, `LB_REQUEST_SAMPLE_RATE` |
| Artisan commands | on | `LB_TRACK_COMMANDS` |
| Scheduled tasks | on | `LB_TRACK_SCHEDULED_TASKS` |
| Query plans for slow SELECTs | off | `LB_QUERY_EXPLAIN` |

Every stream is collected out of the box, so an installation never looks healthy while silently reporting errors only. Each one still spends the account's event quota, so reach for its sample rate or level before switching it off, and never raise a sample rate or switch on query plans unless you were asked to.

## Exceptions

The package registers itself with the exception handler while `larabug.register_exception_handler` is true, so an application does not wire anything up. If you set it to `false` to gain per-exception control, report by hand:

```php
use LaraBug\Facade as LaraBug;

try {
    $this->chargeCustomer($order);
} catch (PaymentFailed $exception) {
    LaraBug::handle($exception);

    throw $exception;
}
```

Never do both. An application that keeps the handler registered and also calls `handle()` reports every exception twice and bills for both.

### Adding context

Context set before an exception is reported travels with it and is cleared per capture:

```php
use LaraBug\LaraBug;

LaraBug::context(['order_id' => $order->id, 'gateway' => 'mollie']);
```

### Suppressing noise

- `larabug.except` lists exception classes that are never sent. `NotFoundHttpException` is there by default.
- `larabug.sleep` (seconds) suppresses a repeat of the same exception, so a hot loop reports once a minute rather than ten thousand times.

### Identifying the user

An authenticated user is attached automatically. A model is sent via `toArray()`, which usually sends more than you want. Implement the interface to choose:

```php
use LaraBug\Concerns\Larabugable;

class User extends Authenticatable implements Larabugable
{
    public function toLarabug(): array
    {
        return ['id' => $this->id, 'plan' => $this->plan];
    }
}
```

## Keeping sensitive data out

`larabug.blacklist` holds glob patterns matched against parameter keys, and it already covers passwords, tokens, authorization, card details, names and emails. Add to it rather than replacing it when the application has its own sensitive fields.

Request monitoring has its own, separate controls, and the cautious defaults are deliberate:

- `capture_headers` is on, but `redact_headers` replaces `authorization`, `cookie` and friends with a marker.
- `capture_payload_on_error` is off, and even when on it only keeps the body of a failed request. `redact_fields` masks keys inside it.
- `capture_mail_recipients` is off. Query strings are never recorded at all, since reset tokens and signed URL signatures live there.
- Cache keys are always templated to their shape before they leave the process, so an id, a uuid or a session id inside a key never is. There is no opt-out, because the key is also the rollup key on the panel and a literal one widens that rollup per value.

Do not switch these on to make debugging easier without saying out loud what starts being stored.

Livewire has two of its own, both on, both filtered by `larabug.blacklist` before anything is kept: `livewire.capture_parameters` (the arguments a component method was called with) and `livewire.capture_updates` (the values properties were changed to). Switch either off to keep the method or property name and drop what came with it.

## Queue jobs

Job tracking is on by default and reports failures at full rate regardless of `jobs.sample_rate`. To opt a specific job in, or to attach tags and metadata:

```php
use LaraBug\Concerns\Trackable;

class ProcessPayment implements ShouldQueue
{
    use Trackable;

    public function larabugTags(): array
    {
        return ['billing'];
    }

    public function larabugMetadata(): array
    {
        return ['order_id' => $this->order->id];
    }
}
```

Or track a single dispatch without touching the job class:

```php
dispatch_tracked(new ProcessPayment($order));
```

Filter volume with `jobs.only_queues`, `jobs.ignore_queues` and `jobs.ignore_jobs` before reaching for `sample_rate`.

A queue whose workers have stopped sends no job records at all, so the scheduler also measures queue depth once a minute (`jobs.depth`). It measures the default connection's queue and every queue Horizon supervises in the current environment. Set `jobs.depth.queues` to a list of `connection:queue` strings, such as `['redis:default', 'redis:emails']`, to measure exactly those instead.

## Tracing across applications

Every request, job, command and scheduled task has a trace id. A queued job names the trace that dispatched it as its parent, and a request that arrives with a W3C `traceparent` header names the caller's trace as its parent, which is how one story is followed from one monitored application into another. The receiving application only reads that header with request monitoring on (`LB_TRACK_REQUESTS`).

For that header to go out, list the hosts of your own applications in `requests.trace_propagation_targets`:

```
LB_TRACE_PROPAGATION_TARGETS=api.example.com,*.internal.example.com
```

The list is empty by default, which sends the header nowhere. Keep it that way for third parties: a payment provider or a webhook receiver has no use for your trace id. `*.example.com` matches any host below example.com but not example.com itself, so list both when both are wanted. Hosts are compared case insensitively and without the port, and nothing is read as a regular expression. A `traceparent` the call already sets is kept.

Only calls made through Laravel's `Http` client get the header. Guzzle used directly, outside the `Http` facade, is out of reach, so set the header yourself there with `LaraBug\Requests\Traceparent::forCurrentTrace()` when the other end is yours.

## Livewire

On by default, and free in an application that does not have Livewire installed: nothing loads a Livewire class or runs unless the container has one.

It exists because a Livewire update is otherwise unreadable. Every component in the application posts to the same endpoint, so the route says nothing, and the body is a snapshot blob that no exception can be read against. An exception thrown in a component therefore carries the component it happened in, the method the client asked it to run, that method's arguments and the properties the client changed.

Arguments arrive from the browser as a positional list, which nothing can filter. They are named from the component method's own signature first, so `save($reference, $password)` is scrubbed as `password` rather than slipping through as an anonymous string. Component state itself is never collected, only the updates.

Component lifecycle timings (mount, hydrate, update, call, render, dehydrate) land on the request timeline beside queries and cache calls, so they also need `LB_TRACK_REQUESTS`. The request record carries names only and never a component value.

```
LB_TRACK_LIVEWIRE=false
LB_LIVEWIRE_CAPTURE_PARAMETERS=false
LB_LIVEWIRE_CAPTURE_UPDATES=false
```

## Query plans

A slow query on a request record does not say why it was slow. With `LB_QUERY_EXPLAIN=true`, LaraBug asks the database for the plan of a slow SELECT and attaches a summary to that query: whether it scans a whole table, which table, the planner's row estimate, and the index it used or could have used. That is how the panel tells a missing index apart from a query that is merely slow.

It is off by default because it runs a statement of LaraBug's own against the application's database. What runs is plain `EXPLAIN` on MySQL and MariaDB, and `EXPLAIN (FORMAT JSON)` on Postgres, with the original bindings on the same connection. Never `EXPLAIN ANALYZE`, so the query itself is not executed again. Other drivers are never explained.

Only a query already kept on a sampled request is considered, and only when it is a single read only SELECT (no `FOR UPDATE`, no write inside a CTE) that took at least `queries.explain.min_duration_ms` (100 by default). Each statement is explained at most once per `queries.explain.interval` seconds (3600 by default), tracked in the default cache store so the interval holds across processes. Queries inside `DB::pretend()` are skipped.

The EXPLAIN runs after the response has been sent, so it never delays the query or the response, and a failing one is swallowed. MariaDB caps it at one second. MySQL and Postgres run it uncapped, since neither can cap a single statement safely outside a transaction. The bindings are only used to run it: neither they nor the raw plan are sent.

```
LB_QUERY_EXPLAIN=true
LB_QUERY_EXPLAIN_MIN_DURATION_MS=100
LB_QUERY_EXPLAIN_INTERVAL=3600
```

Switch it on only when asked, and say what it runs.

## Logs

On by default. The package defines a `larabug-logs` channel and adds it to the application's default channel itself: appended to it when that is a stack, or wrapped together with it in a new `larabug-stack` when it is a single channel. The application keeps logging exactly where it did before. Naming the channel in the stack yourself is fine, it is never added twice.

```
LB_LOGS_LEVEL=info
LB_LOGS_ENABLED=false
```

Logs run at far higher volume than exceptions and count against the plan, so `debug` from production is rarely what anyone wants. `LB_LOGS_ENABLED=false` stops shipping without touching the logging config. Defining your own channel called `larabug-logs` overrides the package's.

### Deprecations

Laravel writes PHP and Laravel deprecations to a channel of their own (`LOG_DEPRECATIONS_CHANNEL`), which discards them unless the application names one. The package points that channel at LaraBug. If the application already sends deprecations somewhere, that destination keeps them and LaraBug is stacked next to it. `LOG_DEPRECATIONS_TRACE` works as it does in Laravel.

A deprecation fires on every call, so each one is sent at most once an hour for the whole application, with the number of occurrences it stands for. The count is kept in the default cache store across requests and processes. With an `array` or `null` store each process sends its own record, so point `LB_LOG_DEPRECATIONS_CACHE_STORE` at a shared store if the default is not one. They travel with the other log lines as warnings on the `deprecations` channel and count against the same allowance. They are on whenever logs are:

```
LB_LOG_DEPRECATIONS=false
LB_LOG_DEPRECATIONS_INTERVAL=3600
LB_LOG_DEPRECATIONS_CACHE_STORE=redis
```

## CVE scanning

Scans `composer.lock` against LaraBug's database and raises findings as issues. Only package names, versions and a hash of the lock file leave the application.

`LB_CVE_ENABLED` alone does not start scanning: the project also has to have CVE scanning enabled in LaraBug. Until it does, the server answers 403 and the client backs off for an hour, so leaving it on is close to free. Triggering is by request piggyback, the scheduler, or both (`LB_CVE_TRIGGER`).

## JavaScript errors

Include the client in a layout to report browser errors to the same project:

```blade
@larabugJavaScriptClient
```

The client posts to `/larabug-api/javascript-report`, which every visitor can reach, so the route is rate limited per IP address (`LB_REPORT_THROTTLE`, 60 a minute by default). A browser error reports the message, the stack, the file and the line the browser gave it. No source is read from the server for these: the file a browser names is a URL on the visitor's machine, not a path here.

## Artisan commands

- `php artisan larabug:test` sends a deliberate exception to verify credentials and connectivity. Reach for this first when reporting appears broken.
- `php artisan larabug:scan` runs a CVE scan now.
- `php artisan larabug:queue-depth --show` prints the queue depth samples instead of sending them.

## Testing

Swap in the fake and assert against it. Never assert by pointing tests at the real API.

```php
use LaraBug\Facade as LaraBug;

LaraBug::fake();

$this->post('/orders', $payload)->assertStatus(500);

LaraBug::assertSent(PaymentFailed::class);
```

Available assertions: `assertSent()`, `assertNotSent()`, `assertNothingSent()` and `assertRequestsSent(int $count)`. `assertSent()` and `assertNotSent()` accept an optional callback for asserting against the reported payload.
