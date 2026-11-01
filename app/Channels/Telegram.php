<?php

namespace App\Channels;

use App\Brain\Brain;
use App\Models\Agent;
use App\Models\Conversation;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/** Telegram bot channel: customers chat with the workspace's widget agent. */
class Telegram
{
    public function __construct(protected Brain $brain) {}

    public function api(string $method, array $payload = []): array
    {
        $token = Tenant::current()?->telegram_bot_token;

        return Http::timeout(20)->post("https://api.telegram.org/bot{$token}/{$method}", $payload)->json() ?? [];
    }

    public function connect(string $webhookUrl): array
    {
        $ws = Tenant::current();
        $ws->forceFill(['telegram_secret' => Str::random(48)])->save();

        return $this->api('setWebhook', [
            'url' => $webhookUrl,
            'secret_token' => $ws->telegram_secret,
            'allowed_updates' => ['message'],
        ]);
    }

    public function handle(array $update): void
    {
        $message = $update['message'] ?? null;
        $text = trim((string) ($message['text'] ?? ''));
        $chatId = $message['chat']['id'] ?? null;
        if (! $chatId || $text === '') {
            return;
        }

        $ref = 'tg:'.$chatId;
        $conversation = Conversation::where('channel', 'telegram')->where('external_ref', $ref)->latest('id')->first();

        if ($text === '/start' || $text === '/new') {
            $agent = Agent::where('widget_enabled', true)->first() ?? Agent::default();
            $this->api('sendMessage', ['chat_id' => $chatId, 'text' => $agent?->widget_greeting ?: 'Hi! How can I help you today?']);

            return;
        }

        $this->api('sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);

        try {
            $result = $this->brain->ask($text, $conversation, null, [
                'channel' => 'telegram',
                'external_ref' => $ref,
                'user_ref' => trim(($message['from']['first_name'] ?? '').' '.($message['from']['username'] ?? '')),
                'agent' => $conversation ? null : (Agent::where('widget_enabled', true)->first() ?? Agent::default()),
            ]);
            $reply = $result['message']->content ?: '…';
        } catch (Throwable $e) {
            report($e);
            $reply = 'Sorry, I could not answer right now. Please try again in a moment.';
        }

        foreach (str_split($reply, 4000) as $part) {
            $this->api('sendMessage', ['chat_id' => $chatId, 'text' => $part]);
        }
    }
}
