<?php

namespace App\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Outbound event webhooks, Stripe-style:
 *   header  X-Brain-Signature: t=<unix>,v1=<hex hmac_sha256(secret, "<t>.<body>")>
 *   header  X-Brain-Event, X-Brain-Delivery (idempotency key)
 * Retries with exponential backoff; endpoints auto-disable after 20 consecutive failures.
 */
class Events
{
    public const ALL = [
        'lead.created', 'lead.updated', 'task.created', 'task.completed',
        'conversation.created', 'action.pending', 'action.approved', 'action.rejected',
        'import.completed', 'import.failed', 'export.completed',
    ];

    public static function dispatch(string $event, array $data): void
    {
        $ws = Tenant::current();
        if (! $ws) {
            return;
        }

        $endpoints = Cache::remember(self::cacheKey($ws->id), 60, fn () => WebhookEndpoint::where('active', true)->get());

        foreach ($endpoints as $endpoint) {
            if (! $endpoint->wants($event)) {
                continue;
            }
            $delivery = WebhookDelivery::create([
                'workspace_id' => $ws->id,
                'webhook_endpoint_id' => $endpoint->id,
                'event' => $event,
                'event_id' => (string) Str::ulid(),
                'payload' => ['event' => $event, 'created_at' => now()->toIso8601String(), 'workspace' => $ws->uuid, 'data' => $data],
            ]);
            DeliverWebhook::dispatch($ws->id, $delivery->id)->onQueue('webhooks')->afterCommit();
        }
    }

    public static function sign(string $secret, string $body, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /** For receivers: verify a signature header with a 5-minute tolerance. */
    public static function verify(string $secret, string $body, string $header, int $tolerance = 300): bool
    {
        if (! preg_match('/t=(\d+),v1=([a-f0-9]{64})/', $header, $m) || abs(time() - (int) $m[1]) > $tolerance) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $m[1].'.'.$body, $secret), $m[2]);
    }

    public static function forget(int $workspaceId): void
    {
        Cache::forget(self::cacheKey($workspaceId));
    }

    protected static function cacheKey(int $workspaceId): string
    {
        return "webhooks:{$workspaceId}";
    }
}
