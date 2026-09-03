<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent synchronously from TenantController::suspend() - a low-volume,
 * platform-admin-only action, so the small added request latency isn't
 * worth the extra complexity of queuing (and this app has no queue-based
 * mail elsewhere to be consistent with - see TestMail/TenantWelcomeMail).
 */
class TenantSuspensionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $companyName,
        public ?string $supportEmail,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your {$this->companyName} account has been suspended",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.tenant-suspension',
        );
    }
}
