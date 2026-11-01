<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Security\UrlGuard;
use App\Webhooks\Events;
use Throwable;

class DeliverWebhook extends TenantJob
{
    public int $tries = 6;

    public int $timeout = 30;

    public function __construct(int $workspaceId, public int $deliveryId)
    {
        parent::__construct($workspaceId);
    }

    public function backoff(): array
    {
        return [10, 60, 300, 1800, 7200];
    }

    public function process(): void
    {
        $delivery = WebhookDelivery::with('endpoint')->find($this->deliveryId);
        $endpoint = $delivery?->endpoint;
        if (! $delivery || ! $endpoint || ! $endpoint->active || $delivery->status === 'delivered') {
            return;
        }

        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $delivery->increment('attempts');

        try {
            $response = UrlGuard::http($endpoint->url)->timeout(15)->withBody($body, 'application/json')->withHeaders([
                'User-Agent' => config('saas.name').' Webhooks',
                'X-Brain-Event' => $delivery->event,
                'X-Brain-Delivery' => $delivery->event_id,
                'X-Brain-Signature' => Events::sign($endpoint->secret, $body, time()),
            ])->post($endpoint->url);

            $delivery->update(['response_code' => $response->status(), 'response_body' => mb_substr($response->body(), 0, 2000)]);

            if ($response->successful()) {
                $delivery->update(['status' => 'delivered']);
                $endpoint->update(['failures' => 0]);

                return;
            }
            throw new \RuntimeException('HTTP '.$response->status());
        } catch (Throwable $e) {
            $delivery->update(['status' => 'failed', 'response_body' => mb_substr($delivery->response_body ?: $e->getMessage(), 0, 2000)]);
            $endpoint->increment('failures');
            if ($endpoint->failures >= 20) {
                $endpoint->update(['active' => false]);
                Events::forget($endpoint->workspace_id);
            }
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff()[$this->attempts() - 1] ?? 7200);
            }
        }
    }
}
