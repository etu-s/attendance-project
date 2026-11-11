<?php

declare(strict_types=1);

namespace App\Modules\Core\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * An email with one link to set a password: a reset ("Forgot password") or
 * an invitation. Queued, and built in the context it was queued from (the
 * queue tenancy bootstrapper restores the tenant).
 */
final class PasswordLinkMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $heading,
        public readonly string $intro,
        public readonly string $actionLabel,
        public readonly string $url,
        public readonly string $footnote,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.password-link');
    }
}
