<?php

namespace LaraBug\Mail;

/**
 * An execution a message can belong to: a request, a job or a command.
 */
interface RecordsMail
{
    /**
     * @param  array<string, mixed>  $message
     */
    public function recordMail(array $message): void;
}
