<?php

namespace Tests\Feature;

use App\Brain\Drivers\OpenAiCompatibleDriver;
use App\Jobs\TenantJob;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\AuthEvent;
use App\Models\Invitation;
use App\Models\SocialAccount;
use App\Models\Usage;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\BelongsToWorkspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Deterministic back-pressure: project conventions enforced as tests (ADR 0020). */
class ArchitectureTest extends TestCase
{
    use RefreshDatabase;

    /** Looked up before a tenant exists (token/invite/SSO) or written system-wide (audit); each filters by workspace explicitly. */
    private const UNSCOPED = [
        Workspace::class, User::class, ApiToken::class,
        AuditLog::class, AuthEvent::class, Invitation::class,
        Usage::class, SocialAccount::class,
    ];

    private function phpFiles(string $dir): array
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir)));
        $out = [];
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }

        return $out;
    }

    public function test_no_vendor_llm_sdks_declared(): void
    {
        $c = json_decode(file_get_contents(base_path('composer.json')), true);
        foreach (array_keys(($c['require'] ?? []) + ($c['require-dev'] ?? [])) as $pkg) {
            $this->assertDoesNotMatchRegularExpression('#^(openai-php|anthropic|google/generative|google-gemini-php|mistralai)#', $pkg, "Vendor LLM SDK $pkg is forbidden");
        }
    }

    public function test_tenant_facing_http_goes_through_url_guard(): void
    {
        // Fixed first-party hosts (vendor APIs we configure) may call Http directly; anything
        // that can take a tenant-chosen URL must use UrlGuard::http().
        $allowed = ['Security/UrlGuard.php', 'Channels/Slack.php', 'Channels/Telegram.php', 'Channels/WhatsApp.php', 'Brain/Drivers/Driver.php'];
        foreach ($this->phpFiles('app') as $file) {
            $rel = str_replace(base_path('app').'/', '', $file);
            if (in_array($rel, $allowed, true)) {
                continue;
            }
            $this->assertStringNotContainsString('Http::', file_get_contents($file), "$rel calls Http:: directly; use UrlGuard::http()");
        }
    }

    public function test_queued_jobs_extend_tenant_job(): void
    {
        foreach ($this->phpFiles('app/Jobs') as $file) {
            $class = 'App\\Jobs\\'.basename($file, '.php');
            if ($class === TenantJob::class || ! class_exists($class)) {
                continue;
            }
            if (is_subclass_of($class, ShouldQueue::class)) {
                // IngestDocument carries its own workspaceId and re-enters the tenant explicitly.
                $ok = is_subclass_of($class, TenantJob::class) || str_contains(file_get_contents($file), 'Tenant::');
                $this->assertTrue($ok, "$class must extend TenantJob or enter a Tenant explicitly");
            }
        }
    }

    public function test_tenant_tables_use_belongs_to_workspace(): void
    {
        foreach ($this->phpFiles('app/Models') as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            $table = (new $class)->getTable();
            if (Schema::hasColumn($table, 'workspace_id') && ! in_array($class, self::UNSCOPED, true)) {
                $this->assertContains(BelongsToWorkspace::class, class_uses_recursive($class), "$class has workspace_id but no BelongsToWorkspace");
            }
        }
    }

    public function test_tenant_chosen_provider_url_is_guarded_at_call_time(): void
    {
        config(['brain.security.allow_private_urls' => false]);
        $driver = new class('t', ['base_url' => 'http://169.254.169.254/v1', 'guard_url' => true]) extends OpenAiCompatibleDriver
        {
            public function client()
            {
                return $this->http();
            }
        };
        $this->expectException(\InvalidArgumentException::class);
        $driver->client();
    }

    public function test_platform_default_provider_url_is_not_guarded(): void
    {
        $d = new class('t', ['base_url' => 'http://localhost:11434/v1']) extends OpenAiCompatibleDriver
        {
            public function client()
            {
                return $this->http();
            }
        };
        $this->assertNotNull($d->client());
    }

    public function test_saving_a_private_provider_url_is_rejected(): void
    {
        config(['brain.security.allow_private_urls' => false]);
        [$user] = $this->tenant();
        $r = $this->actingAs($user)->post('/settings/providers', ['provider' => 'custom', 'api_key' => 'k', 'base_url' => 'http://127.0.0.1:8080/v1', 'driver' => 'openai']);
        $r->assertSessionHasErrors('base_url');
    }
}
