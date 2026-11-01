<?php

namespace App\Brain\Drivers;

use App\Brain\Contracts\Tool;
use App\Brain\Data\LlmResponse;
use App\Brain\Data\ToolCall;
use Illuminate\Http\Client\PendingRequest;

/**
 * Speaks the OpenAI Chat Completions dialect, which nearly every provider and
 * local runtime implements: OpenAI, Azure, Groq, DeepSeek, Mistral, xAI,
 * OpenRouter, Together, Fireworks, Ollama, LM Studio, vLLM, LocalAI...
 */
class OpenAiCompatibleDriver extends Driver
{
    protected function http(): PendingRequest
    {
        $request = parent::http();
        $key = $this->config['api_key'] ?? null;

        if ($key) {
            $header = $this->config['auth_header'] ?? 'Authorization';
            $request = $header === 'Authorization'
                ? $request->withToken($key)
                : $request->withHeaders([$header => $key]);
        }

        if (! empty($this->config['query'])) {
            $request = $request->withQueryParameters($this->config['query']);
        }

        return $request;
    }

    public function chat(array $messages, array $tools = [], array $options = []): LlmResponse
    {
        $payload = $this->payload($messages, $tools, $options);
        $data = $this->ensureOk($this->http()->post('/chat/completions', $payload));

        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];

        $toolCalls = array_map(fn ($c) => new ToolCall(
            $c['id'] ?? 'call_'.bin2hex(random_bytes(6)),
            $c['function']['name'] ?? '',
            self::decodeArguments($c['function']['arguments'] ?? '{}'),
        ), $message['tool_calls'] ?? []);

        return new LlmResponse(
            content: $message['content'] ?? null,
            toolCalls: $toolCalls,
            inputTokens: (int) ($data['usage']['prompt_tokens'] ?? 0),
            outputTokens: (int) ($data['usage']['completion_tokens'] ?? 0),
            model: $data['model'] ?? $payload['model'],
            finishReason: $choice['finish_reason'] ?? null,
            raw: $data,
        );
    }

    public function stream(array $messages, array $tools, array $options, callable $onText): LlmResponse
    {
        $payload = $this->payload($messages, $tools, $options) + ['stream' => true];
        if ($this->config['stream_usage'] ?? str_contains((string) ($this->config['base_url'] ?? ''), 'openai')) {
            $payload['stream_options'] = ['include_usage' => true];
        }

        $text = '';
        $calls = [];
        $usage = [];
        $model = $payload['model'];
        $finish = null;

        foreach ($this->events($this->streamingPost('/chat/completions', $payload)) as $chunk) {
            $model = $chunk['model'] ?? $model;
            $usage = $chunk['usage'] ?? $usage;
            $choice = $chunk['choices'][0] ?? null;
            if (! $choice) {
                continue;
            }
            $finish = $choice['finish_reason'] ?? $finish;
            $delta = $choice['delta'] ?? [];
            if (isset($delta['content']) && $delta['content'] !== '') {
                $text .= $delta['content'];
                $onText($delta['content']);
            }
            foreach ($delta['tool_calls'] ?? [] as $tc) {
                $i = $tc['index'] ?? count($calls);
                $calls[$i]['id'] = $tc['id'] ?? ($calls[$i]['id'] ?? null);
                $calls[$i]['name'] = ($calls[$i]['name'] ?? '').($tc['function']['name'] ?? '');
                $calls[$i]['arguments'] = ($calls[$i]['arguments'] ?? '').($tc['function']['arguments'] ?? '');
            }
        }

        ksort($calls);

        return new LlmResponse(
            content: $text !== '' ? $text : null,
            toolCalls: array_values(array_map(fn ($c) => new ToolCall($c['id'] ?? 'call_'.bin2hex(random_bytes(6)), $c['name'] ?? '', self::decodeArguments($c['arguments'] ?: '{}')), $calls)),
            inputTokens: (int) ($usage['prompt_tokens'] ?? 0),
            outputTokens: (int) ($usage['completion_tokens'] ?? 0),
            model: $model,
            finishReason: $finish,
        );
    }

    protected function payload(array $messages, array $tools, array $options): array
    {
        $payload = [
            'model' => $options['model'] ?? $this->model(),
            'messages' => array_map(fn ($m) => $this->mapMessage($m), $messages),
        ];

        if ($tools) {
            $payload['tools'] = array_map(fn (Tool $t) => [
                'type' => 'function',
                'function' => [
                    'name' => $t->name(),
                    'description' => $t->description(),
                    'parameters' => $this->schema($t),
                ],
            ], array_values($tools));
            $payload['tool_choice'] = $options['tool_choice'] ?? 'auto';
        }

        if (($temperature = $this->temperature($options)) !== null) {
            $payload['temperature'] = $temperature;
        }
        if ($max = $this->maxTokens($options)) {
            $payload[$this->config['max_tokens_param'] ?? 'max_tokens'] = $max;
        }

        return $payload;
    }

    public function embed(array $texts): array
    {
        $data = $this->ensureOk($this->http()->post('/embeddings', [
            'model' => $this->config['embedding_model'],
            'input' => array_values($texts),
        ]));

        return collect($data['data'] ?? [])->sortBy('index')->pluck('embedding')->values()->all();
    }

    protected function mapMessage(array $m): array
    {
        return match ($m['role']) {
            'assistant' => array_filter([
                'role' => 'assistant',
                'content' => $m['content'] ?? null,
                'tool_calls' => ! empty($m['tool_calls']) ? array_map(fn ($c) => [
                    'id' => $c['id'],
                    'type' => 'function',
                    'function' => ['name' => $c['name'], 'arguments' => json_encode((object) ($c['arguments'] ?? []))],
                ], $m['tool_calls']) : null,
            ], fn ($v) => $v !== null) + ['content' => null],
            'tool' => ['role' => 'tool', 'tool_call_id' => $m['tool_call_id'], 'content' => (string) $m['content']],
            default => ['role' => $m['role'], 'content' => (string) $m['content']],
        };
    }
}
