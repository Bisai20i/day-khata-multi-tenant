<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent synchronously from CreateTenantFirstAdmin::handle() - that job is
 * already queued (see routes/../TenancyServiceProvider), so sending mail
 * synchronously inside it never blocks an HTTP request; no need to queue
 * the mail itself too.
 */
class TenantWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $companyName,
        public string $adminName,
        public string $loginUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Welcome to {$this->companyName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.tenant-welcome',
        );
    }
}
