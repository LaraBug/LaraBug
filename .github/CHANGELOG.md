# Changelog

All notable changes to `larabug` will be documented in this file

## 4.0.0-beta.3 - 2026-10-13

- Outgoing mail is now recorded wherever it is sent, not only from HTTP requests. Each message belongs to the innermost execution that sent it: a queue job, else an artisan command, else the request. Completed and failed job records and command records carry `mail` and `mail_sent`, and job records carry the `sample_rate` they were kept at.
- A queued mailable or queued notification is recorded by the worker that delivers it, with `queued = 1` and the real mailable or notification class. With job tracking on, the request that dispatched it no longer records a marker for it; with job tracking off, it still does.
- A send that never completes is recorded as failed on the job or command it started in, the same as on a request.
- A notification sent over the mail channel is now recorded as mail too, named after the notification class, as well as in the notification stream.

## 1.3.1 - 2019-10-11

- Cleanups & L6
