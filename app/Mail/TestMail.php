<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent synchronously (not ShouldQueue) from the platform settings page's
 * "Send test email" action, so the admin gets immediate pass/fail feedback
 * on whether the configured mail settings actually work.
 */
class TestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $platformName) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Test email from {$this->platformName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.test',
        );
    }
}
