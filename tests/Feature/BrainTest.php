<?php

namespace Tests\Feature;

use App\Brain\Knowledge\Chunker;
use App\Brain\Knowledge\KnowledgeBase;
use App\Models\PendingAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        [$this->user, $this->workspace, $this->token] = $this->tenant();
        config([
            'brain.knowledge.queue' => false,
            'brain.embeddings.provider' => 'fake',
            'brain.default' => 'openai',
            'brain.providers.openai.api_key' => 'sk-test',
            'brain.providers.anthropic.api_key' => 'ant-test',
            'brain.providers.gemini.api_key' => 'gem-test',
            'brain.generation.retries' => 0,
        ]);
    }

    protected $user;

    protected $workspace;

    protected $token;

    protected function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    public function test_api_requires_key(): void
    {
        $this->postJson('/api/brain/chat', ['message' => 'hi'])->assertUnauthorized();
    }

    public function test_chunker_splits_with_overlap(): void
    {
        $chunks = (new Chunker(200, 40))->split(str_repeat('Sentence number one is here. ', 40));
        $this->assertGreaterThan(3, count($chunks));
        foreach ($chunks as $c) {
            $this->assertLessThanOrEqual(240, mb_strlen($c));
        }
    }

    public function test_knowledge_ingest_and_search_with_rag_answer(): void
    {
        $kb = app(KnowledgeBase::class);
        $doc = $kb->addText('Refund Policy', "Customers can request a full refund within 14 days of purchase.\n\nShipping is free above 5000 PKR.");
        $kb->addText('Office', 'Our office is in Abbottabad and opens at 9am.');

        $this->assertSame('ready', $doc->status);
        $results = $kb->search('refund within days');
        $this->assertSame('Refund Policy', $results->first()['title']);

        Http::fake(['api.openai.com/*' => Http::response([
            'model' => 'gpt-5-mini',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'You have 14 days [Refund Policy].'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10],
        ])]);

        $res = $this->withHeaders($this->headers())->postJson('/api/brain/chat', ['message' => 'What is the refund policy?'])
            ->assertOk()
            ->assertJsonPath('answer', 'You have 14 days [Refund Policy].')
            ->assertJsonPath('sources.0.title', 'Refund Policy');

        Http::assertSent(fn ($r) => str_contains($r['messages'][0]['content'], '14 days') && $r->hasHeader('Authorization', 'Bearer sk-test'));

        // memory: second turn carries the first
        $this->withHeaders($this->headers())->postJson('/api/brain/chat', ['message' => 'And shipping?', 'conversation_id' => $res->json('conversation_id')])->assertOk();
        Http::assertSent(fn ($r) => collect($r['messages'])->contains(fn ($m) => $m['content'] === 'What is the refund policy?'));
    }

    public function test_openai_tool_loop_creates_lead(): void
    {
        Http::fakeSequence('api.openai.com/*')
            ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
                'id' => 'call_1', 'type' => 'function',
                'function' => ['name' => 'create_lead', 'arguments' => json_encode(['name' => 'Sara', 'company' => 'Nova Traders', 'value' => 5000])],
            ]]], 'finish_reason' => 'tool_calls']]])
            ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Sara from Nova Traders is in the CRM.'], 'finish_reason' => 'stop']]]);

        $this->withHeaders($this->headers())->postJson('/api/brain/chat', ['message' => 'Add Sara from Nova Traders worth 5000'])
            ->assertOk()
            ->assertJsonPath('tool_runs.0.tool', 'create_lead')
            ->assertJsonPath('answer', 'Sara from Nova Traders is in the CRM.');

        $this->assertDatabaseHas('brain_leads', ['name' => 'Sara', 'company' => 'Nova Traders']);
        Http::assertSent(fn ($r) => collect($r['messages'] ?? [])->contains(fn ($m) => $m['role'] === 'tool' && $m['tool_call_id'] === 'call_1'));
    }

    public function test_anthropic_tool_loop_and_message_mapping(): void
    {
        Http::fakeSequence('api.anthropic.com/*')
            ->push(['model' => 'claude-sonnet-4-5', 'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 50, 'output_tokens' => 5], 'content' => [
                ['type' => 'text', 'text' => 'Checking.'],
                ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'business_report', 'input' => ['days' => 7]],
            ]])
            ->push(['model' => 'claude-sonnet-4-5', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'No leads yet this week.']]]);

        $this->withHeaders($this->headers())->postJson('/api/brain/chat', ['message' => 'How did sales go?', 'provider' => 'anthropic'])
            ->assertOk()->assertJsonPath('answer', 'No leads yet this week.')->assertJsonPath('provider', 'anthropic');

        Http::assertSent(function ($r) {
            if (! isset($r['messages'][2])) {
                return false;
            }

            return $r->hasHeader('x-api-key', 'ant-test')
                && is_string($r['system'])
                && $r['messages'][1]['content'][1]['type'] === 'tool_use'
                && $r['messages'][2]['content'][0]['type'] === 'tool_result'
                && $r['messages'][2]['content'][0]['tool_use_id'] === 'tu_1';
        });
    }

    public function test_gemini_tool_loop(): void
    {
        Http::fakeSequence('generativelanguage.googleapis.com/*')
            ->push(['candidates' => [['content' => ['role' => 'model', 'parts' => [
                ['functionCall' => ['name' => 'create_task', 'args' => ['title' => 'Call Sara', 'priority' => 'high']], 'thoughtSignature' => 'sig123'],
            ]], 'finishReason' => 'STOP']]])
            ->push(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Task added.']]], 'finishReason' => 'STOP']], 'modelVersion' => 'gemini-2.5-flash']);

        $this->withHeaders($this->headers())->postJson('/api/brain/chat', ['message' => 'Remind me to call Sara', 'provider' => 'gemini'])
            ->assertOk()->assertJsonPath('answer', 'Task added.');

        $this->assertDatabaseHas('brain_tasks', ['title' => 'Call Sara', 'priority' => 'high']);
        Http::assertSent(fn ($r) => isset($r['contents'][1]) && ($r['contents'][1]['parts'][0]['thoughtSignature'] ?? null) === 'sig123'
            && isset($r['contents'][2]['parts'][0]['functionResponse']));
    }

    public function test_risky_action_waits_for_approval_then_runs(): void
    {
        Mail::fake();
        Http::fakeSequence('api.openai.com/*')
            ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
                'id' => 'c1', 'type' => 'function',
                'function' => ['name' => 'send_email', 'arguments' => json_encode(['to' => 'sara@nova.pk', 'subject' => 'Quote', 'body' => 'Hi Sara'])],
            ]]]]]])
            ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => 'The email is awaiting approval.']]]]);

        $this->withHeaders($this->headers())->postJson('/api/brain/chat', ['message' => 'Email Sara the quote'])->assertOk();

        Mail::assertNothingOutgoing();
        $action = PendingAction::firstOrFail();
        $this->assertSame('pending', $action->status);

        $this->withHeaders($this->headers())->postJson("/api/brain/actions/{$action->id}/approve")
            ->assertOk()->assertJsonPath('status', 'approved');
        $this->withHeaders($this->headers())->postJson("/api/brain/actions/{$action->id}/approve")->assertStatus(409);
    }

    public function test_file_upload_is_ingested(): void
    {
        $file = UploadedFile::fake()->createWithContent('prices.csv', "product,price\nBasic plan,1000\nPro plan,4500\n");

        $this->withHeaders($this->headers())->post('/api/brain/documents', ['file' => $file, 'collection' => 'sales'], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('status', 'ready');

        $this->withHeaders($this->headers())->getJson('/api/brain/search?q=pro+plan+price')
            ->assertOk()->assertJsonPath('0.title', 'prices.csv');
    }

    public function test_keyword_search_works_without_embeddings(): void
    {
        config(['brain.embeddings.provider' => null]);
        app(KnowledgeBase::class)->addText('Warranty', 'All machines carry a two year warranty on parts.');

        $this->assertSame('Warranty', app(KnowledgeBase::class)->search('warranty for machines')->first()['title']);
    }

    public function test_provider_errors_are_reported_cleanly(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Invalid API key']], 401)]);

        $this->withHeaders($this->headers())->postJson('/api/brain/chat', ['message' => 'hi'])
            ->assertStatus(502)->assertJsonPath('message', '[openai] HTTP 401: Invalid API key');
    }

    public function test_providers_endpoint(): void
    {
        $this->withHeaders($this->headers())->getJson('/api/brain/providers')
            ->assertOk()->assertJsonPath('default', 'openai')
            ->assertJsonFragment(['name' => 'anthropic']);
    }
}
