<?php

namespace App\Mail;

use App\Models\AdminAccessRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class AdminAccessRequestedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public AdminAccessRequest $accessRequest,
        public string $approveUrl,
        public string $rejectUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: config('app.name').' — Admin access requested',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-access-requested',
        );
    }
}
