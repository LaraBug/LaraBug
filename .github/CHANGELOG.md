# Changelog

All notable changes to `larabug` will be documented in this file

## 4.0.0-beta.12 - 2026-10-19

- Calls the application makes through Laravel's `Http` client now carry a W3C `traceparent` for the current request, job, command or scheduled task, so a second monitored application they reach records itself as its child. It is sent only to hosts listed in `requests.trace_propagation_targets` (`LB_TRACE_PROPAGATION_TARGETS`, comma separated). The list is empty by default, which sends it nowhere, so a trace id never reaches a third party unless its host is listed.
- A target is an exact host such as `api.example.com`, or `*.example.com` for any host below example.com but not example.com itself. Hosts are compared case insensitively and without the port. No target is read as a regular expression.
- A `traceparent` the call already carries is kept, and a call made while no trace has started gets none. Guzzle used directly, outside the `Http` facade, is not reached and never sends the header. LaraBug's own reports are unaffected.
- A queued job and a scheduled task now start their trace when they begin instead of on first use, so a call they make before anything else is reported already carries it.

## 4.0.0-beta.11 - 2026-10-19

- An attempt after a job's first is never sampled out by `jobs.sample_rate`. An earlier attempt may have been reported as `released` or `processing`, and the server keeps the last record it was sent, so a dropped completion left the job looking released or stuck until the server's sweep marked it abandoned. Such a record carries a `sample_rate` of 1.0. This costs volume: with a sample rate below 1.0, every retried job that completes is now sent.
- With `jobs.track_processing` on, a processing record is now kept or dropped together with its attempt's completion. Before, every processing record was sent while its completion could be sampled out, which left the job looking stuck. Whether an attempt is kept is now decided by its job's uuid instead of a random draw, so both records get the same answer.

## 4.0.0-beta.10 - 2026-10-19

- A job that fails itself with `$this->fail()` without throwing is now reported once, as `failed`. Laravel fires the processed event after the failed one for such a job, so it used to be followed by a `completed` record as well.
- An attempt that ends without a processed, failed or release event, such as a job that releases itself and then throws, no longer leaves its times and duration in memory for the life of the worker. They are dropped when the next job off a real queue starts. The same goes for the duration kept for a job released after an exception. Such an attempt still sends no record of its own.

## 4.0.0-beta.9 - 2026-10-19

- Completed and failed queue job records now always carry `available_at` and `reserved_at`. Both are recorded when a job starts, whether or not its processing record is sent, so under the default config (where `jobs.track_processing` is off) the server can still tell when a job was due and how long it waited. `jobs.track_processing` only decides whether the processing record itself is sent.
- A job that releases itself with `$this->release()` is now reported with status `released` instead of `completed`. The record carries the attempt's `duration_ms`, `memory_usage` and mail, and no `completed_at`. Laravel does not keep the delay a job releases itself with, so the `available_at` of a released record is the moment the job was released, not the moment it is due again.
- A released record is never sampled out by `jobs.sample_rate`, and it is sent with `jobs.track_completed` off too, so the server does not keep the attempt before it as the job's last state.
- The attempt after a release reports its own `reserved_at`. Its `available_at` is the first dispatch's, as in beta.8, unless it was released after an exception with a backoff Laravel reports.
- The times of an attempt are forgotten once it ends, including when its record is sampled out or not sent. Before, with `jobs.track_processing` on, a completed record that was sampled out kept them for the life of the worker. The due time of a retry released after an exception is kept for the 1000 most recent releases only, since another worker may pick the retry up.

## 4.0.0-beta.8 - 2026-10-19

- Without Horizon a queue job's `available_at` now comes from the payload's `createdAt`, which Laravel writes itself, plus its `delay`. Before, a job without Horizon's `pushedAt` reported the moment a worker picked it up, the same as its `reserved_at`. `pushedAt` is still preferred when it is there, and a job carrying neither still reports the moment it was picked up.
- Laravel writes `createdAt` and `delay` into the payload from 12.11. On Laravel 11, and on 12 before 12.11, a job without Horizon still reports the moment it was picked up, and a delayed job pushed through Horizon reports its push time without the delay.
- A job that throws and is released for a retry no longer repeats the first attempt's `reserved_at` and `available_at` when the same worker runs it again. Its next attempt's `available_at` is the release time plus the backoff, which Laravel puts on `JobReleasedAfterException` from 12.48. Before 12.48, or when another worker picks the retry up, the next attempt reports the `available_at` of the first dispatch.
- A job that releases itself with `$this->release()` still reports the `available_at` of the first dispatch on its next attempt, since Laravel does not expose the delay it was released with.

## 4.0.0-beta.7 - 2026-10-19

- A queue job's `available_at` is now when the job became due instead of when it was dispatched. For a job dispatched with a delay that is its push time plus the payload's `delay`, so a deliberately delayed job no longer looks like one that waited for a worker the whole time. A job dispatched without a delay reports its push time, as before.
- The push time is the payload's `pushedAt`, which Horizon writes. Without it `available_at` is still the moment a worker picked the job up.
- A job released back onto the queue keeps its original payload, so its later attempts report the `available_at` of the first dispatch, not the end of the release backoff.

## 4.0.0-beta.6 - 2026-10-19

- Queue depth is now sampled from the scheduler once a minute by `larabug:queue-depth` and sent as a `queue_depth_batch`. Each sample carries the pending, delayed and reserved counts and when the oldest pending job was queued, so a queue nobody drains shows up even though no worker reports on it. The finer counts are null on Laravel versions whose queues do not provide them.
- The default connection's queue is measured, along with every queue Horizon supervises in the current environment. `jobs.depth.queues` takes a list of `connection:queue` strings that replaces that discovery. `LB_QUEUE_DEPTH=false` turns it off, and it follows `LB_TRACK_JOBS` otherwise. It needs `schedule:run` to be running.
- The command exits with a failure code when the samples could not be sent. A server that does not know the type yet answers 422, which is not treated as a failure.

## 4.0.0-beta.5 - 2026-10-19

- A 429 from the server now holds the refused stream instead of being ignored. The hold follows `Retry-After` (seconds or an HTTP date), else `X-RateLimit-Reset`, else 30 seconds, and never lasts longer than a minute. Only the refused stream waits: exceptions keep being reported while telemetry is rate limited, and the other way round.
- Telemetry refused with a 429 is no longer discarded. Queue jobs, log lines, requests, commands and scheduled tasks go back into their buffer, keep collecting during the hold, and are sent once it has passed. Each buffer holds at most ten batches. Past that the oldest records are dropped and, with `jobs.report_buffer_errors` on, reported as a `buffer_error` with `lost_events` once sending resumes. A web request that ends during a hold loses what it buffered rather than waiting.
- An exception refused with a 429 holds exception reporting for the same short window, the way a 402 does for the billing limit. Exceptions raised inside that window are not sent.
- `larabug:test` and `larabug:scan` say when the project is being rate limited.
- A 402 behaves exactly as before.

## 4.0.0-beta.4 - 2026-10-19

- Buffered queue job records are now sent once `jobs.flush_interval` has passed even while the worker sits idle. Before, the interval was only checked when the next job came in, so the tail of every burst waited for more work.
- A worker that stops, gracefully or by killing itself over a timed out job, flushes its buffer first. A timed out job's kill skips the shutdown function, so those records used to be lost.

## 4.0.0-beta.3 - 2026-10-13

- Outgoing mail is now recorded wherever it is sent, not only from HTTP requests. Each message belongs to the innermost execution that sent it: a queue job, else an artisan command, else the request. Completed and failed job records and command records carry `mail` and `mail_sent`, and job records carry the `sample_rate` they were kept at.
- A queued mailable or queued notification is recorded by the worker that delivers it, with `queued = 1` and the real mailable or notification class. With job tracking on, the request that dispatched it no longer records a marker for it; with job tracking off, it still does.
- A send that never completes is recorded as failed on the job or command it started in, the same as on a request.
- A notification sent over the mail channel is now recorded as mail too, named after the notification class, as well as in the notification stream.

## 1.3.1 - 2019-10-11

- Cleanups & L6
