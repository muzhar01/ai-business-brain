<?php

namespace App\Brain\Tools;

use App\Brain\Contracts\Tool;
use App\Models\Conversation;
use App\Security\UrlGuard;
use App\Tenancy\Tenant;
use Illuminate\Support\Str;

/**
 * Bridge to everything else: n8n, Zapier, Make, your ERP, WhatsApp, Slack...
 * Posts {event, payload, conversation} to BRAIN_WEBHOOK_URL. Needs approval.
 */
class TriggerWebhookTool implements Tool
{
    public function enabled(): bool
    {
        return filled($this->url());
    }

    public function name(): string
    {
        return 'trigger_automation';
    }

    public function description(): string
    {
        return 'Trigger an external business automation (invoice, WhatsApp/Slack message, ERP order, calendar booking) by sending an event with a payload.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event' => ['type' => 'string', 'description' => 'snake_case event name, e.g. create_invoice, notify_team'],
                'payload' => ['type' => 'object', 'description' => 'Event data'],
            ],
            'required' => ['event'],
        ];
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    public function handle(array $arguments, ?Conversation $conversation = null): string|array
    {
        $response = UrlGuard::http($this->url())->timeout(30)->post($this->url(), [
            'event' => $arguments['event'],
            'payload' => $arguments['payload'] ?? new \stdClass,
            'conversation' => $conversation?->uuid,
            'sent_at' => now()->toIso8601String(),
        ]);

        return $response->successful()
            ? 'Automation triggered: '.Str::limit($response->body(), 500)
            : 'Automation failed with HTTP '.$response->status();
    }

    protected function url(): ?string
    {
        return Tenant::current()?->setting('webhook_url') ?: config('brain.tools.webhook_url');
    }
}
