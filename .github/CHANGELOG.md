# Changelog

All notable changes to `larabug` will be documented in this file

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
