<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Task;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaletteSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected(): void
    {
        $this->get('/search/palette?q=ab')->assertRedirect();
    }

    public function test_short_query_returns_nothing(): void
    {
        [$u] = $this->tenant();
        Lead::create(['name' => 'Alice']);
        $this->actingAs($u)->getJson('/search/palette?q=a')->assertOk()->assertExactJson(['results' => []]);
    }

    public function test_finds_leads_and_tasks_with_relative_links(): void
    {
        [$u] = $this->tenant();
        Lead::create(['name' => 'Alice Smith', 'company' => 'Acme', 'notes' => 'secretnote']);
        Task::create(['title' => 'Call Alice']);
        $r = $this->actingAs($u)->getJson('/search/palette?q=alice')->assertOk();
        $r->assertJsonCount(2, 'results');
        $this->assertStringNotContainsString('secretnote', $r->getContent());
        foreach ($r->json('results') as $x) {
            $this->assertStringStartsWith('/', $x['href']);
        }
    }

    public function test_wildcards_are_literal_and_limit_applies(): void
    {
        [$u] = $this->tenant();
        foreach (range(1, 8) as $i) {
            Lead::create(['name' => "Bob $i"]);
        }
        $this->actingAs($u)->getJson('/search/palette?q=bob')->assertJsonCount(5, 'results');
        $this->actingAs($u)->getJson('/search/palette?q=%25%25')->assertJsonCount(0, 'results');
    }

    public function test_tenant_isolation(): void
    {
        [$u1] = $this->tenant();
        Lead::create(['name' => 'Zed Other']);
        [$u2] = $this->tenant();
        $this->assertNotNull(Tenant::current());
        $this->actingAs($u2)->getJson('/search/palette?q=zed')->assertJsonCount(0, 'results');
    }

    public function test_palette_js_is_safe(): void
    {
        $p = file_get_contents(resource_path('views/partials/command-palette.blade.php'));
        $this->assertStringNotContainsString('innerHTML', $p);
        $this->assertStringContainsString('AbortController', $p);
        $this->assertStringContainsString("charAt(1) !== '/'", $p);
    }
}
