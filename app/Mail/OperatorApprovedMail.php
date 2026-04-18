<?php

namespace App\Mail;

use App\Models\Operator;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OperatorApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Operator $operator,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your operator account has been approved',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.operator-approved',
        );
    }
}
