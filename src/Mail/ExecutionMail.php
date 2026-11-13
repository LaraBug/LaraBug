<?php

namespace LaraBug\Mail;

/**
 * The mail one job or one command sent.
 *
 * The console counterpart to the mail a RequestMonitor holds, and capped the
 * same way: a job that mails ten thousand customers is worth knowing about,
 * and the counter is what says so, not the ten thousandth entry.
 */
class ExecutionMail implements RecordsMail
{
    /** @var array<int, array<string, mixed>> */
    protected array $mail = [];

    protected int $sent = 0;

    protected readonly float $start;

    protected readonly int $maxMail;

    public function __construct()
    {
        $this->start = microtime(true);

        // The request setting, not one of its own: a cap on how much mail one
        // execution carries means the same thing whichever kind it is.
        $this->maxMail = (int) config('larabug.requests.max_mail', 50);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    public function recordMail(array $message): void
    {
        $this->sent++;

        if (count($this->mail) >= $this->maxMail) {
            return;
        }

        // Recorded once the send is over, so the offset is where we are now
        // less however long the send took. Floored at zero for the same reason
        // a request's offsets are: a clock that stepped backwards is not worth
        // a bar that starts before the job did.
        $elapsedMs = (microtime(true) - $this->start) * 1000;

        $message['start_ms'] = max(0.0, round($elapsedMs - (float) ($message['duration_ms'] ?? 0), 3));

        $this->mail[] = $message;
    }

    /**
     * @return array{
     *     mail: array<int, array<string, mixed>>,
     *     mail_sent: int
     * }
     */
    public function toArray(): array
    {
        return [
            'mail' => $this->mail,
            'mail_sent' => $this->sent,
        ];
    }
}
