<?php

namespace Tests\Feature;

use App\Brain\LlmManager;
use App\Models\Message;
use App\Models\Usage;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StreamingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'brain.providers.openai.api_key' => 'sk-test',
            'brain.providers.anthropic.api_key' => 'ak-test',
            'brain.providers.gemini.api_key' => 'g-test',
            'brain.embeddings.provider' => null,
            'brain.generation.retries' => 0,
        ]);
    }

    public static function sse(array $events, string $sep = "\n\n"): string
    {
        return implode('', array_map(fn ($e) => 'data: '.(is_string($e) ? $e : json_encode($e)).$sep, $events));
    }

    protected function collect(string $provider): array
    {
        $chunks = [];
        $r = app(LlmManager::class)->provider($provider)->stream([['role' => 'user', 'content' => 'hi']], [], [], function ($t) use (&$chunks) {
            $chunks[] = $t;
        });

        return [$r, $chunks];
    }

    public function test_openai_streams_text_and_usage(): void
    {
        Http::fake(['*' => Http::response(self::sse([
            ['model' => 'gpt-x', 'choices' => [['delta' => ['role' => 'assistant', 'content' => 'Hel']]]],
            ['choices' => [['delta' => ['content' => 'lo']]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'stop']]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 2]],
            '[DONE]',
        ]), 200, ['Content-Type' => 'text/event-stream'])]);

        [$r, $chunks] = $this->collect('openai');
        $this->assertSame(['Hel', 'lo'], $chunks);
        $this->assertSame('Hello', $r->content);
        $this->assertSame([7, 2], [$r->inputTokens, $r->outputTokens]);
        $this->assertSame('stop', $r->finishReason);
        $this->assertSame('gpt-x', $r->model);
        Http::assertSent(fn ($req) => $req['stream'] === true && str_ends_with($req->url(), '/chat/completions'));
    }

    public function test_openai_assembles_tool_call_fragments(): void
    {
        Http::fake(['*' => Http::response(self::sse([
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'c1', 'function' => ['name' => 'create_', 'arguments' => '{"ti']]]]]]],
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['name' => 'task', 'arguments' => 'tle":"Call Ali"}']]]]]]],
            ['choices' => [['delta' => ['tool_calls' => [['index' => 1, 'id' => 'c2', 'function' => ['name' => 'current_datetime', 'arguments' => '']]]]]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]],
            '[DONE]',
        ]))]);

        [$r] = $this->collect('openai');
        $this->assertNull($r->content);
        $this->assertCount(2, $r->toolCalls);
        $this->assertSame('create_task', $r->toolCalls[0]->name);
        $this->assertSame(['title' => 'Call Ali'], $r->toolCalls[0]->arguments);
        $this->assertSame('c2', $r->toolCalls[1]->id);
        $this->assertSame([], $r->toolCalls[1]->arguments);
    }

    public function test_anthropic_streams_text_and_tool_use(): void
    {
        Http::fake(['*' => Http::response(
            "event: message_start\n".self::sse([['type' => 'message_start', 'message' => ['model' => 'claude-x', 'usage' => ['input_tokens' => 11]]]]).
            self::sse([
                ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Let me ']],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'check.']],
                ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 'tu1', 'name' => 'search_knowledge', 'input' => []]],
                ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"query":']],
                ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '"refunds"}']],
                ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 9]],
                ['type' => 'message_stop'],
            ])
        )]);

        [$r, $chunks] = $this->collect('anthropic');
        $this->assertSame(['Let me ', 'check.'], $chunks);
        $this->assertSame('Let me check.', $r->content);
        $this->assertSame('search_knowledge', $r->toolCalls[0]->name);
        $this->assertSame(['query' => 'refunds'], $r->toolCalls[0]->arguments);
        $this->assertSame([11, 9, 'tool_use', 'claude-x'], [$r->inputTokens, $r->outputTokens, $r->finishReason, $r->model]);
    }

    public function test_anthropic_stream_error_event_raises(): void
    {
        Http::fake(['*' => Http::response(self::sse([['type' => 'error', 'error' => ['message' => 'Overloaded']]]))]);
        $this->expectExceptionMessage('Overloaded');
        $this->collect('anthropic');
    }

    public function test_gemini_streams_chunks(): void
    {
        Http::fake(['*' => Http::response(self::sse([
            ['candidates' => [['content' => ['parts' => [['text' => 'Sales ']]]]]],
            ['candidates' => [['content' => ['parts' => [['text' => 'are up.']]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 4], 'modelVersion' => 'gemini-x'],
        ], "\r\n\r\n"))]);

        [$r, $chunks] = $this->collect('gemini');
        $this->assertSame(['Sales ', 'are up.'], $chunks);
        $this->assertSame([5, 4, 'STOP', 'gemini-x'], [$r->inputTokens, $r->outputTokens, $r->finishReason, $r->model]);
        Http::assertSent(fn ($req) => str_contains($req->url(), ':streamGenerateContent?alt=sse'));
    }

    public function test_gemini_streamed_function_call(): void
    {
        Http::fake(['*' => Http::response(self::sse([
            ['candidates' => [['content' => ['parts' => [['functionCall' => ['name' => 'query_dataset', 'args' => ['dataset' => 'sales']]]]]]]],
        ]))]);
        [$r] = $this->collect('gemini');
        $this->assertSame('query_dataset', $r->toolCalls[0]->name);
        $this->assertSame(['dataset' => 'sales'], $r->toolCalls[0]->arguments);
    }

    public function test_stream_http_errors_are_readable(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid API key']], 401)]);
        $this->expectExceptionMessage('Invalid API key');
        $this->collect('openai');
    }

    public function test_fake_driver_streams_word_by_word(): void
    {
        $chunks = [];
        app(LlmManager::class)->provider('fake')->stream([['role' => 'user', 'content' => 'a b c']], [], [], function ($t) use (&$chunks) {
            $chunks[] = $t;
        });
        $this->assertSame(['Echo: ', 'a ', 'b ', 'c'], $chunks);
    }

    public function test_chat_stream_endpoint_emits_sse_events_and_persists(): void
    {
        [$user] = $this->tenant();
        Http::fake(['*' => Http::response(self::sse([
            ['choices' => [['delta' => ['content' => 'Hi ']]]],
            ['choices' => [['delta' => ['content' => 'there']]]],
            '[DONE]',
        ]))]);

        $res = $this->actingAs($user)->post('/app/api/chat/stream', ['message' => 'hello'], ['Accept' => 'text/event-stream']);
        $res->assertOk();
        $this->assertStringStartsWith('text/event-stream', $res->headers->get('Content-Type'));
        $this->assertSame('no', $res->headers->get('X-Accel-Buffering'));
        $body = $res->streamedContent();

        $this->assertStringContainsString("event: delta\ndata: {\"text\":\"Hi \"}", $body);
        $this->assertStringContainsString("event: delta\ndata: {\"text\":\"there\"}", $body);
        $this->assertMatchesRegularExpression('/event: done\ndata: \{.*"answer":"Hi there"/', $body);
        $this->assertDatabaseHas('brain_messages', ['role' => 'assistant']);
        $this->assertSame('Hi there', Message::where('role', 'assistant')->first()->content);
    }

    public function test_chat_stream_reports_tool_events(): void
    {
        [$user] = $this->tenant();
        Http::fakeSequence()
            ->push(self::sse([['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'c1', 'function' => ['name' => 'current_datetime', 'arguments' => '{}']]]]]]], '[DONE]']))
            ->push(self::sse([['choices' => [['delta' => ['content' => 'It is late.']]]], '[DONE]']));

        $body = $this->actingAs($user)->post('/app/api/chat/stream', ['message' => 'time?'])->streamedContent();
        $this->assertStringContainsString('event: tool', $body);
        $this->assertStringContainsString('"name":"current_datetime"', $body);
        $this->assertStringContainsString('"answer":"It is late."', $body);
    }

    public function test_chat_stream_errors_become_error_events(): void
    {
        [$user] = $this->tenant();
        Http::fake(['*' => Http::response(['error' => ['message' => 'Rate limited']], 429)]);
        $body = $this->actingAs($user)->post('/app/api/chat/stream', ['message' => 'x'])->streamedContent();
        $this->assertStringContainsString('event: error', $body);
        $this->assertStringContainsString('Rate limited', $body);
    }

    public function test_chat_stream_validates_and_enforces_quota_before_streaming(): void
    {
        [$user, $ws] = $this->tenant('free');
        $this->actingAs($user)->postJson('/app/api/chat/stream', [])->assertStatus(422);
        Usage::record($ws, ['messages' => 1_000_000]);
        $this->actingAs($user)->postJson('/app/api/chat/stream', ['message' => 'x'])->assertStatus(402);
    }

    public function test_chat_stream_via_api_token(): void
    {
        [, , $token] = $this->tenant();
        Tenant::forget();
        Http::fake(['*' => Http::response(self::sse([['choices' => [['delta' => ['content' => 'ok']]]], '[DONE]']))]);
        $body = $this->withToken($token)->post('/api/brain/chat/stream', ['message' => 'x'])->streamedContent();
        $this->assertStringContainsString('"answer":"ok"', $body);
    }

    public function test_chat_stream_requires_auth(): void
    {
        $this->postJson('/api/brain/chat/stream', ['message' => 'x'])->assertUnauthorized();
        $this->post('/app/api/chat/stream', ['message' => 'x'])->assertRedirect();
    }
}
