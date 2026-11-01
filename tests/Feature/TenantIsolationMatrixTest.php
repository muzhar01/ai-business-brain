<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Chunk;
use App\Models\Conversation;
use App\Models\Dataset;
use App\Models\DatasetRow;
use App\Models\Document;
use App\Models\Export;
use App\Models\Import;
use App\Models\Lead;
use App\Models\Message;
use App\Models\PendingAction;
use App\Models\Task;
use App\Models\WebhookEndpoint;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Workspace B can never read, count, find, update or delete workspace A's rows. */
class TenantIsolationMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected array $ids = [];

    protected function seedA(): array
    {
        [$user, $a, $tokenA] = $this->tenant();
        $conv = Conversation::create(['title' => 'A secret chat']);
        $doc = Document::create(['title' => 'A doc', 'collection' => 'default', 'source_type' => 'text', 'status' => 'ready']);
        $ds = Dataset::create(['name' => 'A data']);
        $ids = [
            Lead::class => Lead::create(['name' => 'A lead'])->id,
            Task::class => Task::create(['title' => 'A task'])->id,
            Conversation::class => $conv->id,
            Message::class => $conv->messages()->create(['role' => 'assistant', 'content' => 'A secret'])->id,
            Document::class => $doc->id,
            Chunk::class => Chunk::create(['document_id' => $doc->id, 'position' => 0, 'content' => 'A chunk'])->id,
            Dataset::class => $ds->id,
            DatasetRow::class => DatasetRow::create(['dataset_id' => $ds->id, 'data' => ['x' => 1]])->id,
            PendingAction::class => PendingAction::create(['tool' => 'send_email', 'arguments' => [], 'status' => 'pending', 'conversation_id' => $conv->id])->id,
            Agent::class => Agent::first()->id,
            Import::class => Import::create(['name' => 'A imp', 'target' => 'leads'])->id,
            Export::class => Export::create(['target' => 'leads'])->id,
            WebhookEndpoint::class => WebhookEndpoint::create(['url' => 'https://1.1.1.1', 'secret' => 's', 'events' => ['*']])->id,
        ];
        Tenant::forget();

        return [$ids, $conv->uuid, $tokenA];
    }

    public static function models(): iterable
    {
        foreach ([Lead::class, Task::class, Conversation::class, Message::class, Document::class, Chunk::class, Dataset::class, DatasetRow::class, PendingAction::class, Agent::class, Import::class, Export::class, WebhookEndpoint::class] as $m) {
            foreach (['find', 'count', 'update', 'delete', 'exists'] as $op) {
                yield class_basename($m)." {$op}" => [$m, $op];
            }
        }
    }

    #[DataProvider('models')]
    public function test_model_is_invisible_to_other_tenant(string $model, string $op): void
    {
        [$ids] = $this->seedA();
        $this->tenant(); // switch to workspace B
        $id = $ids[$model];

        match ($op) {
            'find' => $this->assertNull($model::find($id)),
            'count' => $this->assertSame($model === Agent::class ? 1 : 0, $model::count()),
            'update' => $this->assertSame(0, $model::whereKey($id)->update(['updated_at' => now()])),
            'delete' => $this->assertSame(0, $model::whereKey($id)->delete()),
            'exists' => $this->assertFalse($model::whereKey($id)->exists()),
        };

        Tenant::forget();
        $this->assertTrue($model::withoutGlobalScopes()->whereKey($id)->exists(), 'row must survive');
    }

    public static function endpoints(): iterable
    {
        $e = [
            ['get', 'conversations/{uuid}'], ['get', 'conversations/{uuid}/export'], ['delete', 'conversations/{uuid}'],
            ['get', 'documents/{'.Document::class.'}'], ['delete', 'documents/{'.Document::class.'}'], ['post', 'documents/{'.Document::class.'}/reprocess'],
            ['post', 'actions/{'.PendingAction::class.'}/approve'], ['post', 'actions/{'.PendingAction::class.'}/reject'],
            ['post', 'messages/{'.Message::class.'}/rate'],
            ['get', 'imports/{'.Import::class.'}'], ['get', 'exports/{'.Export::class.'}'], ['get', 'exports/{'.Export::class.'}/download'],
            ['get', 'datasets/{'.Dataset::class.'}/rows'], ['post', 'datasets/{'.Dataset::class.'}/rows'],
        ];
        foreach ($e as [$m, $p]) {
            yield "{$m} {$p}" => [$m, $p];
        }
    }

    #[DataProvider('endpoints')]
    public function test_api_returns_404_for_foreign_ids(string $method, string $path): void
    {
        [$ids, $uuid] = $this->seedA();
        [, , $tokenB] = $this->tenant();
        Tenant::forget();

        $url = '/api/brain/'.preg_replace_callback('/\{([^}]+)\}/', fn ($m) => $m[1] === 'uuid' ? $uuid : $ids[$m[1]], $path);
        $this->json($method, $url, ['rating' => 1, 'rows' => [['a' => 1]]], ['Authorization' => "Bearer {$tokenB}"])->assertNotFound();
    }

    public static function listings(): iterable
    {
        foreach (['leads', 'tasks', 'conversations', 'documents', 'actions', 'datasets', 'agents'] as $l) {
            yield $l => [$l];
        }
    }

    #[DataProvider('listings')]
    public function test_api_listings_never_include_foreign_rows(string $listing): void
    {
        $this->seedA();
        [, , $tokenB] = $this->tenant();
        Tenant::forget();
        $this->getJson("/api/brain/{$listing}", ['Authorization' => "Bearer {$tokenB}"])->assertOk()
            ->assertDontSee('A lead')->assertDontSee('A task')->assertDontSee('A secret')->assertDontSee('A doc')->assertDontSee('A data');
    }

    public function test_search_and_rag_are_tenant_scoped(): void
    {
        $this->seedA();
        [, , $tokenB] = $this->tenant();
        Tenant::forget();
        $this->getJson('/api/brain/search?q=chunk', ['Authorization' => "Bearer {$tokenB}"])->assertOk()->assertExactJson([]);
    }

    public function test_tokens_are_per_workspace(): void
    {
        [, , $tokenA] = $this->seedA();
        $this->getJson('/api/brain/leads', ['Authorization' => "Bearer {$tokenA}"])->assertOk()->assertSee('A lead');
        Tenant::forget();
        $this->getJson('/api/brain/leads', ['Authorization' => 'Bearer brn_forged'])->assertUnauthorized();
    }
}
