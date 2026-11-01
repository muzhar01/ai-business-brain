<?php

namespace App\Brain\Drivers;

use App\Brain\Contracts\Tool;
use App\Brain\Data\LlmResponse;
use App\Brain\Data\ToolCall;
use App\Brain\Exceptions\BrainException;
use Illuminate\Http\Client\PendingRequest;

class AnthropicDriver extends Driver
{
    protected function http(): PendingRequest
    {
        return parent::http()->withHeaders([
            'x-api-key' => (string) ($this->config['api_key'] ?? ''),
            'anthropic-version' => $this->config['version'] ?? '2023-06-01',
        ]);
    }

    public function chat(array $messages, array $tools = [], array $options = []): LlmResponse
    {
        $payload = $this->payload($messages, $tools, $options);
        $data = $this->ensureOk($this->http()->post('/messages', $payload));

        $text = [];
        $toolCalls = [];
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text[] = $block['text'];
            } elseif (($block['type'] ?? null) === 'tool_use') {
                $toolCalls[] = new ToolCall($block['id'], $block['name'], (array) ($block['input'] ?? []));
            }
        }

        return new LlmResponse(
            content: $text ? implode("\n", $text) : null,
            toolCalls: $toolCalls,
            inputTokens: (int) ($data['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($data['usage']['output_tokens'] ?? 0),
            model: $data['model'] ?? $payload['model'],
            finishReason: $data['stop_reason'] ?? null,
            raw: $data,
        );
    }

    public function stream(array $messages, array $tools, array $options, callable $onText): LlmResponse
    {
        $payload = $this->payload($messages, $tools, $options) + ['stream' => true];
        $text = '';
        $blocks = [];
        $in = $out = 0;
        $model = $payload['model'];
        $stop = null;

        foreach ($this->events($this->streamingPost('/messages', $payload)) as $e) {
            switch ($e['type'] ?? null) {
                case 'message_start':
                    $model = $e['message']['model'] ?? $model;
                    $in = (int) ($e['message']['usage']['input_tokens'] ?? 0);
                    break;
                case 'content_block_start':
                    $blocks[$e['index']] = $e['content_block'] + ['json' => ''];
                    break;
                case 'content_block_delta':
                    $d = $e['delta'] ?? [];
                    if (($d['type'] ?? null) === 'text_delta') {
                        $text .= $d['text'];
                        $onText($d['text']);
                    } elseif (($d['type'] ?? null) === 'input_json_delta') {
                        $blocks[$e['index']]['json'] .= $d['partial_json'];
                    }
                    break;
                case 'message_delta':
                    $stop = $e['delta']['stop_reason'] ?? $stop;
                    $out = (int) ($e['usage']['output_tokens'] ?? $out);
                    break;
                case 'error':
                    throw new BrainException('anthropic: '.($e['error']['message'] ?? 'stream error'));
            }
        }

        $calls = [];
        foreach ($blocks as $b) {
            if (($b['type'] ?? null) === 'tool_use') {
                $calls[] = new ToolCall($b['id'], $b['name'], self::decodeArguments($b['json'] ?: '{}'));
            }
        }

        return new LlmResponse($text !== '' ? $text : null, $calls, $in, $out, $model, $stop);
    }

    protected function payload(array $messages, array $tools, array $options): array
    {
        $system = collect($messages)->where('role', 'system')->pluck('content')->implode("\n\n");

        $payload = [
            'model' => $options['model'] ?? $this->model(),
            'max_tokens' => $this->maxTokens($options) ?: 2048,
            'messages' => $this->mapMessages(array_values(array_filter($messages, fn ($m) => $m['role'] !== 'system'))),
        ];
        if ($system !== '') {
            $payload['system'] = $system;
        }
        if (($temperature = $this->temperature($options)) !== null) {
            $payload['temperature'] = $temperature;
        }
        if ($tools) {
            $payload['tools'] = array_map(fn (Tool $t) => [
                'name' => $t->name(),
                'description' => $t->description(),
                'input_schema' => $this->schema($t),
            ], array_values($tools));
            $payload['tool_choice'] = ['type' => $options['tool_choice'] ?? 'auto'];
        }

        return $payload;
    }

    public function supportsEmbeddings(): bool
    {
        return false;
    }

    public function embed(array $texts): array
    {
        throw BrainException::provider($this->name, 'Anthropic has no embeddings API. Set BRAIN_EMBEDDING_PROVIDER to another provider (openai, gemini, ollama, mistral) or null.');
    }

    /** Claude needs alternating turns; tool results ride in one user turn. */
    protected function mapMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'tool') {
                $block = ['type' => 'tool_result', 'tool_use_id' => $m['tool_call_id'], 'content' => (string) $m['content']];
                $last = array_key_last($out);
                if ($last !== null && $out[$last]['role'] === 'user' && is_array($out[$last]['content'])
                    && ($out[$last]['content'][0]['type'] ?? null) === 'tool_result') {
                    $out[$last]['content'][] = $block;
                } else {
                    $out[] = ['role' => 'user', 'content' => [$block]];
                }

                continue;
            }

            if ($m['role'] === 'assistant') {
                $blocks = [];
                if (filled($m['content'] ?? null)) {
                    $blocks[] = ['type' => 'text', 'text' => $m['content']];
                }
                foreach ($m['tool_calls'] ?? [] as $c) {
                    $blocks[] = ['type' => 'tool_use', 'id' => $c['id'], 'name' => $c['name'], 'input' => (object) ($c['arguments'] ?? [])];
                }
                $out[] = ['role' => 'assistant', 'content' => $blocks ?: [['type' => 'text', 'text' => '...']]];

                continue;
            }

            // merge consecutive user turns
            $last = array_key_last($out);
            if ($last !== null && $out[$last]['role'] === 'user' && is_string($out[$last]['content'])) {
                $out[$last]['content'] .= "\n\n".$m['content'];
            } else {
                $out[] = ['role' => 'user', 'content' => (string) $m['content']];
            }
        }

        return $out;
    }
}
