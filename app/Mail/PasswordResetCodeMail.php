<?php

namespace App\Mail;

use App\Models\PasswordResetCode;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PasswordResetCodeMail extends Mailable
{
    public function __construct(public User $user, public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'رمز استعادة كلمة المرور | Password reset code');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.reset-code', with: ['minutes' => PasswordResetCode::TTL_MINUTES]);
    }
}
