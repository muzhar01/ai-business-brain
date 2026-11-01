<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WorkspaceInvitation extends Mailable
{
    public function __construct(public Invitation $invitation) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Join :workspace on :app', ['workspace' => $this->invitation->workspace->name, 'app' => config('saas.name')]));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.invitation');
    }
}
