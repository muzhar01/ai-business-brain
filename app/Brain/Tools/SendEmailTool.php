<?php

namespace App\Brain\Tools;

use App\Brain\Contracts\Tool;
use App\Models\Conversation;
use Illuminate\Support\Facades\Mail;

/** Uses your Laravel mailer (SMTP, SES, Mailgun, Postmark, Resend...). Needs approval. */
class SendEmailTool implements Tool
{
    public function name(): string
    {
        return 'send_email';
    }

    public function description(): string
    {
        return 'Send an email on behalf of the business (follow-ups, quotes, replies). A human approves it before it goes out.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'to' => ['type' => 'string', 'description' => 'Recipient email address'],
                'subject' => ['type' => 'string'],
                'body' => ['type' => 'string', 'description' => 'Plain-text body, signed off with the business name'],
            ],
            'required' => ['to', 'subject', 'body'],
        ];
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    public function handle(array $arguments, ?Conversation $conversation = null): string|array
    {
        if (! filter_var($arguments['to'], FILTER_VALIDATE_EMAIL)) {
            return 'Invalid recipient address.';
        }

        Mail::raw($arguments['body'], fn ($m) => $m->to($arguments['to'])->subject($arguments['subject']));

        return "Email sent to {$arguments['to']}.";
    }
}
