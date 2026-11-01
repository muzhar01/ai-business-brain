<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Localised per ADR 0017: the caller sets ->locale() (workspace owner's language). */
class DailyBriefing extends Mailable
{
    public function __construct(public string $workspaceName, public string $body) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __(':workspace: your daily AI briefing', ['workspace' => $this->workspaceName]));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.briefing');
    }
}
