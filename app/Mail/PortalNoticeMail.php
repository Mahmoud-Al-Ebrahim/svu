<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Short system notices: session reminders, status changes, new sessions for students. */
class PortalNoticeMail extends Mailable
{
    /** @param  list<string>  $lines */
    public function __construct(public string $heading, public array $lines) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->heading);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.notice', with: ['lines' => $this->lines]);
    }
}
