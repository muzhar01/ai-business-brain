<?php

namespace App\Channels;

use App\Brain\Brain;
use App\Models\Agent;
use App\Models\Conversation;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Slack bot (Events API): answers @mentions in channels (in-thread) and direct messages. */
class Slack
{
    public function __construct(protected Brain $brain) {}

    /** Slack request signing v0 with a 5-minute replay window. */
    public static function validSignature(string $body, ?string $timestamp, ?string $signature, ?string $secret): bool
    {
        if (! $timestamp || ! $signature || ! $secret || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        return hash_equals('v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", $secret), $signature);
    }

    public function handle(array $event): void
    {
        if (! empty($event['bot_id']) || ! empty($event['subtype']) || ! in_array($event['type'] ?? null, ['app_mention', 'message'], true)) {
            return; // ignore our own messages, edits and joins
        }
        if (($event['type'] === 'message') && ($event['channel_type'] ?? null) !== 'im') {
            return; // in channels we only answer when mentioned
        }

        $text = trim(preg_replace('/<@[A-Z0-9]+>/', '', (string) ($event['text'] ?? '')));
        if ($text === '') {
            return;
        }
        $thread = $event['thread_ts'] ?? $event['ts'] ?? null;
        $ref = 'slack:'.$event['channel'].':'.$thread;
        $conversation = Conversation::where('channel', 'slack')->where('external_ref', $ref)->latest('id')->first();

        try {
            $result = $this->brain->ask($text, $conversation, null, [
                'channel' => 'slack',
                'external_ref' => $ref,
                'user_ref' => 'slack:'.($event['user'] ?? ''),
                'agent' => $conversation ? null : Agent::default(),
            ]);
            $answer = $result['message']->content ?: '…';
        } catch (Throwable $e) {
            report($e);
            $answer = ':warning: I could not answer right now.';
        }

        Http::timeout(15)->withToken(Tenant::current()?->slack['bot_token'] ?? '')
            ->post('https://slack.com/api/chat.postMessage', array_filter([
                'channel' => $event['channel'],
                'text' => $answer,
                'thread_ts' => ($event['channel_type'] ?? null) === 'im' ? null : $thread,
                'unfurl_links' => false,
            ], fn ($v) => $v !== null));
    }
}
