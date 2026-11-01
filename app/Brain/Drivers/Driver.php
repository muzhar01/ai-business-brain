<?php

namespace App\Brain\Drivers;

use App\Brain\Contracts\LlmDriver;
use App\Brain\Contracts\Tool;
use App\Brain\Data\LlmResponse;
use App\Brain\Exceptions\BrainException;
use App\Security\UrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

abstract class Driver implements LlmDriver
{
    public function __construct(
        protected string $name,
        protected array $config,
    ) {}

    public function model(): string
    {
        return $this->config['model'] ?? 'unknown';
    }

    public function supportsEmbeddings(): bool
    {
        return ! empty($this->config['embedding_model']);
    }

    protected function http(): PendingRequest
    {
        $generation = config('brain.generation');

        $base = rtrim((string) ($this->config['base_url'] ?? ''), '/');
        $client = ($this->config['guard_url'] ?? false) ? UrlGuard::http($base) : Http::withOptions([]);

        return $client->baseUrl($base)
            ->acceptJson()
            ->asJson()
            ->timeout($generation['timeout'] ?? 120)
            ->retry($generation['retries'] ?? 2, 750, fn ($e) => $this->shouldRetry($e), throw: false)
            ->withHeaders($this->config['headers'] ?? []);
    }

    protected function shouldRetry(\Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }
        if ($e instanceof RequestException) {
            return in_array($e->response->status(), [408, 429, 500, 502, 503, 504, 529], true);
        }

        return false;
    }

    protected function ensureOk(Response $response): array
    {
        if ($response->failed()) {
            $error = $response->json('error.message')
                ?? $response->json('error')
                ?? $response->json('message')
                ?? $response->body();

            throw BrainException::provider($this->name, 'HTTP '.$response->status().': '.(is_string($error) ? $error : json_encode($error)));
        }

        return $response->json() ?? [];
    }

    protected function maxTokens(array $options): ?int
    {
        return $options['max_tokens'] ?? config('brain.generation.max_tokens');
    }

    protected function temperature(array $options): ?float
    {
        return array_key_exists('temperature', $options) ? $options['temperature'] : config('brain.generation.temperature');
    }

    /** JSON schema with an empty properties list encoded as an object, not []. */
    protected function schema(Tool $tool): array
    {
        $schema = $tool->parameters() ?: ['type' => 'object', 'properties' => []];
        $schema['type'] ??= 'object';
        if (empty($schema['properties'])) {
            $schema['properties'] = new \stdClass;
        }

        return $schema;
    }

    protected static function decodeArguments(mixed $arguments): array
    {
        if (is_array($arguments)) {
            return $arguments;
        }
        $decoded = json_decode((string) $arguments, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** Fallback for providers without streaming: one chunk with the whole answer. */
    public function stream(array $messages, array $tools, array $options, callable $onText): LlmResponse
    {
        $response = $this->chat($messages, $tools, $options);
        if ($response->content !== null && $response->content !== '') {
            $onText($response->content);
        }

        return $response;
    }

    protected function streamingPost(string $url, array $payload): Response
    {
        return $this->ensureStreamOk($this->http()->withOptions(['stream' => true])->post($url, $payload));
    }

    protected function ensureStreamOk(Response $response): Response
    {
        if ($response->failed()) {
            $this->ensureOk($response); // throws a readable BrainException
        }

        return $response;
    }

    /**
     * Server-sent events reader: yields each `data:` payload decoded from JSON.
     *
     * @return \Generator<int, array>
     */
    protected function events(Response $response): \Generator
    {
        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        while (true) {
            $eof = $body->eof();
            $buffer .= $eof ? '' : $body->read(8192);
            while (($pos = strpos($buffer, "\n")) !== false || ($eof && $buffer !== '')) {
                $line = $pos === false ? $buffer : substr($buffer, 0, $pos);
                $buffer = $pos === false ? '' : substr($buffer, $pos + 1);
                $line = rtrim($line, "\r");
                if (! str_starts_with($line, 'data:')) {
                    continue;
                }
                $data = trim(substr($line, 5));
                if ($data === '[DONE]') {
                    return;
                }
                $json = json_decode($data, true);
                if (is_array($json)) {
                    yield $json;
                }
            }
            if ($eof) {
                return;
            }
        }
    }
}
