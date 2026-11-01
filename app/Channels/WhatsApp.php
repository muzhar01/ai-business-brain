<?php

namespace App\Channels;

use App\Brain\Brain;
use App\Models\Agent;
use App\Models\Conversation;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\Http;
use Throwable;

/** WhatsApp Business Cloud API (Meta) channel. */
class WhatsApp
{
    public const GRAPH = 'https://graph.facebook.com/v21.0';

    public function __construct(protected Brain $brain) {}

    public function send(string $to, string $text): void
    {
        $cfg = Tenant::current()?->whatsapp ?? [];
        foreach (str_split($text, 4000) as $part) {
            Http::timeout(20)->withToken($cfg['token'] ?? '')
                ->post(self::GRAPH.'/'.($cfg['phone_number_id'] ?? '').'/messages', [
                    'messaging_product' => 'whatsapp',
                    'to' => $to,
                    'type' => 'text',
                    'text' => ['body' => $part, 'preview_url' => false],
                ]);
        }
    }

    /** Meta signs every POST with the app secret (X-Hub-Signature-256: sha256=...). */
    public static function validSignature(string $body, ?string $header, ?string $appSecret): bool
    {
        if (! $header || ! $appSecret || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $body, $appSecret), substr($header, 7));
    }

    public function handle(array $payload): void
    {
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['messages'] ?? [] as $message) {
                    if (($message['type'] ?? null) !== 'text') {
                        continue;
                    }
                    $this->reply((string) $message['from'], trim((string) ($message['text']['body'] ?? '')), $change['value']['contacts'][0]['profile']['name'] ?? null);
                }
            }
        }
    }

    protected function reply(string $from, string $text, ?string $name): void
    {
        if ($text === '') {
            return;
        }
        $ref = 'wa:'.$from;
        $conversation = Conversation::where('channel', 'whatsapp')->where('external_ref', $ref)->where('updated_at', '>', now()->subDay())->latest('id')->first();

        try {
            $result = $this->brain->ask($text, $conversation, null, [
                'channel' => 'whatsapp',
                'external_ref' => $ref,
                'user_ref' => $name,
                'agent' => $conversation ? null : (Agent::where('widget_enabled', true)->first() ?? Agent::default()),
            ]);
            $answer = $result['message']->content ?: '…';
        } catch (Throwable $e) {
            report($e);
            $answer = 'Sorry, I could not answer right now. Please try again shortly.';
        }

        $this->send($from, $answer);
    }
}
