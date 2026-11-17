<?php

namespace App\Mail;

use App\Models\SentEmail;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A tutor's message to students, sent from the platform but replying to the tutor's university address. */
class ClassMessageMail extends Mailable
{
    public function __construct(public SentEmail $email) {}

    public function envelope(): Envelope
    {
        $sender = $this->email->sender;

        return new Envelope(
            replyTo: [new Address($sender->email, $sender->name_en)],
            subject: $this->email->subject,
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.class-message', with: ['body' => $this->email->body, 'sender' => $this->email->sender]);
    }

    public function attachments(): array
    {
        return $this->email->attachments
            ->map(fn ($a) => Attachment::fromStorageDisk('local', $a->storage_path)->as($a->original_name))
            ->all();
    }
}
