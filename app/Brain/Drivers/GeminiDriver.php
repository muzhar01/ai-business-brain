<?php

namespace App\Brain\Drivers;

use App\Brain\Contracts\Tool;
use App\Brain\Data\LlmResponse;
use App\Brain\Data\ToolCall;
use Illuminate\Http\Client\PendingRequest;

class GeminiDriver extends Driver
{
    protected function http(): PendingRequest
    {
        return parent::http()->withHeaders(['x-goog-api-key' => (string) ($this->config['api_key'] ?? '')]);
    }

    public function chat(array $messages, array $tools = [], array $options = []): LlmResponse
    {
        $model = $options['model'] ?? $this->model();
        $data = $this->ensureOk($this->http()->post("/models/{$model}:generateContent", $this->payload($messages, $tools, $options)));
        [$text, $toolCalls] = $this->parts($data);

        return new LlmResponse(
            content: $text !== '' ? $text : null,
            toolCalls: $toolCalls,
            inputTokens: (int) ($data['usageMetadata']['promptTokenCount'] ?? 0),
            outputTokens: (int) ($data['usageMetadata']['candidatesTokenCount'] ?? 0),
            model: $data['modelVersion'] ?? $model,
            finishReason: $data['candidates'][0]['finishReason'] ?? null,
            raw: $data,
        );
    }

    public function stream(array $messages, array $tools, array $options, callable $onText): LlmResponse
    {
        $model = $options['model'] ?? $this->model();
        $text = '';
        $calls = [];
        $usage = [];
        $finish = null;

        foreach ($this->events($this->streamingPost("/models/{$model}:streamGenerateContent?alt=sse", $this->payload($messages, $tools, $options))) as $chunk) {
            [$t, $c] = $this->parts($chunk);
            if ($t !== '') {
                $text .= $t;
                $onText($t);
            }
            array_push($calls, ...$c);
            $usage = $chunk['usageMetadata'] ?? $usage;
            $finish = $chunk['candidates'][0]['finishReason'] ?? $finish;
            $model = $chunk['modelVersion'] ?? $model;
        }

        return new LlmResponse($text !== '' ? $text : null, $calls, (int) ($usage['promptTokenCount'] ?? 0), (int) ($usage['candidatesTokenCount'] ?? 0), $model, $finish);
    }

    /** @return array{0: string, 1: array<int, ToolCall>} */
    protected function parts(array $data): array
    {
        $text = '';
        $toolCalls = [];
        foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (isset($part['text']) && empty($part['thought'])) {
                $text .= $part['text'];
            }
            if (isset($part['functionCall'])) {
                $toolCalls[] = new ToolCall(
                    $part['functionCall']['id'] ?? 'call_'.bin2hex(random_bytes(6)),
                    $part['functionCall']['name'],
                    (array) ($part['functionCall']['args'] ?? []),
                    isset($part['thoughtSignature']) ? ['thoughtSignature' => $part['thoughtSignature']] : [],
                );
            }
        }

        return [$text, $toolCalls];
    }

    protected function payload(array $messages, array $tools, array $options): array
    {
        $system = collect($messages)->where('role', 'system')->pluck('content')->implode("\n\n");

        $payload = ['contents' => $this->mapMessages(array_values(array_filter($messages, fn ($m) => $m['role'] !== 'system')))];

        if ($system !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        }
        if ($tools) {
            $payload['tools'] = [[
                'functionDeclarations' => array_map(function (Tool $t) {
                    $declaration = ['name' => $t->name(), 'description' => $t->description()];
                    if (! empty($t->parameters()['properties'])) {
                        $declaration['parameters'] = $t->parameters();
                    }

                    return $declaration;
                }, array_values($tools)),
            ]];
            $payload['toolConfig'] = ['functionCallingConfig' => ['mode' => strtoupper($options['tool_choice'] ?? 'auto')]];
        }

        $generationConfig = array_filter([
            'temperature' => $this->temperature($options),
            'maxOutputTokens' => $this->maxTokens($options),
        ], fn ($v) => $v !== null);
        if ($generationConfig) {
            $payload['generationConfig'] = $generationConfig;
        }

        return $payload;
    }

    public function embed(array $texts): array
    {
        $model = $this->config['embedding_model'];
        $dimensions = config('brain.embeddings.dimensions');

        $data = $this->ensureOk($this->http()->post("/models/{$model}:batchEmbedContents", [
            'requests' => array_map(fn ($t) => array_filter([
                'model' => "models/{$model}",
                'content' => ['parts' => [['text' => $t]]],
                'outputDimensionality' => $dimensions ?: null,
            ]), array_values($texts)),
        ]));

        return array_map(fn ($e) => $e['values'], $data['embeddings'] ?? []);
    }

    protected function mapMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            [$role, $parts] = match ($m['role']) {
                'assistant' => ['model', array_merge(
                    filled($m['content'] ?? null) ? [['text' => $m['content']]] : [],
                    array_map(fn ($c) => array_filter([
                        'functionCall' => ['name' => $c['name'], 'args' => (object) ($c['arguments'] ?? [])],
                        'thoughtSignature' => $c['meta']['thoughtSignature'] ?? null,
                    ]), $m['tool_calls'] ?? []),
                )],
                'tool' => ['user', [['functionResponse' => ['name' => $m['name'] ?? 'tool', 'response' => ['content' => (string) $m['content']]]]]],
                default => ['user', [['text' => (string) $m['content']]]],
            };

            if ($parts === []) {
                $parts = [['text' => '...']];
            }

            $last = array_key_last($out);
            if ($last !== null && $out[$last]['role'] === $role) {
                $out[$last]['parts'] = array_merge($out[$last]['parts'], $parts);
            } else {
                $out[] = ['role' => $role, 'parts' => $parts];
            }
        }

        return $out;
    }
}
