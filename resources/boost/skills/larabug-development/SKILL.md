---
name: larabug-development
description: Use when configuring the LaraBug package, controlling what it reports, or sending data to LaraBug from application code. Covers config/larabug.php and the LB_* environment variables, filtering sensitive data, manual exception reporting, per-exception context, queue job tracking, Livewire component tracking, log shipping, CVE scanning, the JavaScript client, and testing with the LaraBug fake.
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

`larabug.environments` defaults to `['production']`. Nothing is sent from any other environment, which is the usual reason a developer reports that "LaraBug is not working" locally. Set `LB_ENVIRONMENTS` to a comma separated list to add one, for instance `LB_ENVIRONMENTS=production,staging` on a staging server. Add the environment deliberately rather than removing the check, and remember an empty list disables reporting everywhere.

The list only gates exceptions and the queue heartbeat. Jobs, requests, commands, scheduled tasks, logs and CVE scans are sent from whatever environment they happen in, so an application that reports no exceptions can still be spending its event quota.

### What is on by default

| Feature | Default | Switch |
|---|---|---|
| Exceptions | on | `larabug.register_exception_handler` |
| Queue jobs | on | `LB_TRACK_JOBS` |
| Livewire components | on | `LB_TRACK_LIVEWIRE` |
| CVE scanning | on | `LB_CVE_ENABLED` |
| Logs | off | name the `larabug-logs` channel in your log stack |
| HTTP requests | off | `LB_TRACK_REQUESTS` |
| Artisan commands | off | `LB_TRACK_COMMANDS` |
| Scheduled tasks | off | `LB_TRACK_SCHEDULED_TASKS` |
| Queue heartbeat | on | `LB_HEARTBEAT`, needs `schedule:run` |

The off-by-default monitors are off because each one spends the account's event quota. Never switch one on unless you were asked to.

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
- `capture_cache_keys` and `capture_mail_recipients` are off. Query strings are never recorded at all, since reset tokens and signed URL signatures live there.

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

## Logs

The package defines a `larabug-logs` channel. Naming it in the stack is the opt-in:

```
LOG_STACK=single,larabug-logs
LB_LOGS_LEVEL=info
```

Logs run at far higher volume than exceptions and count against the plan, so `debug` from production is rarely what anyone wants. Defining your own channel called `larabug-logs` overrides the package's.

## CVE scanning

Scans `composer.lock` against LaraBug's database and raises findings as issues. Only package names, versions and a hash of the lock file leave the application.

`LB_CVE_ENABLED` alone does not start scanning: the project also has to have CVE scanning enabled in LaraBug. Until it does, the server answers 403 and the client backs off for an hour, so leaving it on is close to free. Triggering is by request piggyback, the scheduler, or both (`LB_CVE_TRIGGER`).

## JavaScript errors

Include the client in a layout to report browser errors to the same project:

```blade
@larabugJavaScriptClient
```

## Artisan commands

- `php artisan larabug:test` sends a deliberate exception to verify credentials and connectivity. Reach for this first when reporting appears broken.
- `php artisan larabug:scan` runs a CVE scan now.
- `php artisan larabug:heartbeat --show` prints the worker payload instead of sending it.

## Testing

Swap in the fake and assert against it. Never assert by pointing tests at the real API.

```php
use LaraBug\Facade as LaraBug;

LaraBug::fake();

$this->post('/orders', $payload)->assertStatus(500);

LaraBug::assertSent(PaymentFailed::class);
```

Available assertions: `assertSent()`, `assertNotSent()`, `assertNothingSent()` and `assertRequestsSent(int $count)`. `assertSent()` and `assertNotSent()` accept an optional callback for asserting against the reported payload.
