<?php

namespace LaraBug\Mail;

use LaraBug\Support\ClassName;

/**
 * The shape of one mail entry, whichever execution it lands on.
 *
 * A request, a job and a command all carry the same entry, so the panel reads
 * one shape for all three and a message reads the same wherever it was sent.
 */
class MailEntry
{
    /**
     * A message that went out.
     *
     * @return array<string, mixed>
     */
    public static function sent(object $message, string $mailable, string $subject, int $queued, float $durationMs): array
    {
        $to = static::addresses(static::recipients($message, 'getTo'));
        $cc = static::addresses(static::recipients($message, 'getCc'));
        $bcc = static::addresses(static::recipients($message, 'getBcc'));

        return [
            'mailable' => $mailable,
            'subject' => $subject,
            'to_count' => count($to),
            'cc_count' => count($cc),
            'bcc_count' => count($bcc),
            'recipient_domains' => static::recipientList(array_merge($to, $cc, $bcc)),
            'queued' => $queued,
            'failed' => 0,
            'duration_ms' => $durationMs,
        ];
    }

    /**
     * A send that started and never finished.
     *
     * @return array<string, mixed>
     */
    public static function unfinished(string $mailable, string $subject, int $queued, float $durationMs): array
    {
        return [
            'mailable' => $mailable,
            'subject' => $subject,
            // A send that threw reached no recipient, and the counts are what a
            // recipient tile would add up.
            'to_count' => 0,
            'cc_count' => 0,
            'bcc_count' => 0,
            'recipient_domains' => '',
            'queued' => $queued,
            'failed' => 1,
            'duration_ms' => $durationMs,
        ];
    }

    /**
     * A mailable that was queued rather than sent, read off the mailable the
     * job carries: there is no message yet, the send is a worker away.
     * Recipients are filled in by queue time, so counts and domains are known;
     * the subject often is not, since an envelope resolves it at render, and
     * the duration cannot be, so both are left for the send itself.
     *
     * @return array<string, mixed>
     */
    public static function dispatched(object $mailable): array
    {
        $to = static::mailableAddresses($mailable, 'to');
        $cc = static::mailableAddresses($mailable, 'cc');
        $bcc = static::mailableAddresses($mailable, 'bcc');

        return [
            'mailable' => ClassName::normalised(get_class($mailable)),
            'subject' => (string) ($mailable->subject ?? ''),
            'to_count' => count($to),
            'cc_count' => count($cc),
            'bcc_count' => count($bcc),
            'recipient_domains' => static::recipientList(array_merge($to, $cc, $bcc)),
            'queued' => 1,
            // Queued at this point and nothing more. Whether it sends is the
            // worker's story.
            'failed' => 0,
            'duration_ms' => 0.0,
        ];
    }

    /**
     * One address list off a message, across mail engines: Symfony's Email has
     * the getters and returns Address objects, the older Swift message had the
     * same getter names and returned an [address => name] map, and a message
     * without the getter contributes nobody.
     *
     * @return array<int|string, mixed>
     */
    protected static function recipients(object $message, string $getter): array
    {
        if (! method_exists($message, $getter)) {
            return [];
        }

        return (array) $message->{$getter}();
    }

    /**
     * The address strings in a recipient list, whichever engine produced it:
     * Symfony hands over Address objects, Swift keyed the address and put the
     * name in the value.
     *
     * @param  array<int|string, mixed>  $recipients
     * @return array<int, string>
     */
    protected static function addresses(array $recipients): array
    {
        $addresses = [];

        foreach ($recipients as $key => $value) {
            if (is_object($value) && method_exists($value, 'getAddress')) {
                $addresses[] = (string) $value->getAddress();

                continue;
            }

            if (is_string($key) && $key !== '') {
                $addresses[] = $key;

                continue;
            }

            if (is_string($value) && $value !== '') {
                $addresses[] = $value;
            }
        }

        return $addresses;
    }

    /**
     * The addresses in one of a mailable's recipient lists. A Mailable holds
     * its to, cc and bcc as public arrays of ['name' => ..., 'address' => ...],
     * a different shape from the getters a sent message exposes.
     *
     * @return array<int, string>
     */
    protected static function mailableAddresses(object $mailable, string $property): array
    {
        $recipients = $mailable->{$property} ?? [];

        if (! is_array($recipients)) {
            return [];
        }

        $addresses = [];

        foreach ($recipients as $recipient) {
            if (is_array($recipient) && isset($recipient['address'])) {
                $addresses[] = (string) $recipient['address'];

                continue;
            }

            if (is_string($recipient) && $recipient !== '') {
                $addresses[] = $recipient;
            }
        }

        return $addresses;
    }

    /**
     * Stored as the domains a message reached, deduped, never the addresses:
     * a bounce is a bounce to gmail.com, not to a person, and the local part
     * is the customer data the whole position rests on not keeping. Opting in
     * capture_mail_recipients keeps the full addresses, the same shape the
     * payload capture takes.
     *
     * @param  array<int, string>  $addresses
     */
    protected static function recipientList(array $addresses): string
    {
        if (config('larabug.requests.capture_mail_recipients', false)) {
            return implode(',', $addresses);
        }

        $domains = [];

        foreach ($addresses as $address) {
            $at = strrpos($address, '@');

            if ($at !== false) {
                $domains[static::normalisedDomain(substr($address, $at + 1))] = true;
            }
        }

        return implode(',', array_keys($domains));
    }

    protected static function normalisedDomain(string $domain): string
    {
        return strtolower(trim($domain, " \t\n\r\0\x0B>"));
    }
}
