<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Task;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * N+1 guard (LaravelDaily tips 46, 56, 66: eager load, withCount, constrained eager loads).
 * Each page is requested with 1 row and with 25 rows of data; the number of SQL queries
 * must not grow with the data. Strict mode also turns any lazy load into an exception.
 */
class QueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    public static function pages(): array
    {
        return [
            'dashboard' => ['/dashboard'],
            'chat' => ['/chat'],
            'leads' => ['/leads'],
            'tasks' => ['/tasks'],
            'knowledge' => ['/knowledge'],
            'approvals' => ['/approvals'],
            'datasets' => ['/datasets'],
            'imports' => ['/imports'],
            'exports' => ['/exports'],
            'settings' => ['/settings'],
            'team' => ['/settings/team'],
            'privacy' => ['/settings/privacy'],
            'webhooks' => ['/settings/webhooks'],
            'channels' => ['/settings/channels'],
            'api' => ['/settings/api'],
            'billing' => ['/billing'],
            'profile' => ['/profile'],
            'security' => ['/profile/security'],
        ];
    }

    protected function seedRows(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $lead = Lead::create(['name' => "Lead {$i}", 'email' => "l{$i}@example.com", 'status' => 'new', 'value' => 100 + $i]);
            Task::create(['title' => "Task {$i}", 'lead_id' => $lead->id, 'due_date' => now()->addDays($i)->toDateString()]);
            $c = Conversation::create(['title' => "Conv {$i}"]);
            $c->messages()->create(['role' => 'user', 'content' => "hello {$i}"]);
            AuditLog::record('test.event', $lead);
        }
    }

    protected function queries(string $uri, $user): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $res = $this->actingAs($user)->get($uri);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertContains($res->status(), [200, 302], "{$uri} returned {$res->status()}");

        return $n;
    }

    #[DataProvider('pages')]
    public function test_query_count_does_not_scale_with_rows(string $uri): void
    {
        [$user, $ws] = $this->tenant();
        $this->seedRows(1);
        $this->queries($uri, $user); // warm caches (plan, settings)
        $small = $this->queries($uri, $user);
        Tenant::set($ws);
        $this->seedRows(24);
        $large = $this->queries($uri, $user);

        $this->assertLessThanOrEqual($small + 2, $large, "{$uri}: {$small} queries with 1 row but {$large} with 25 (N+1)");
    }

    #[DataProvider('pages')]
    public function test_page_stays_under_absolute_budget(string $uri): void
    {
        [$user] = $this->tenant();
        $this->seedRows(10);
        $this->assertLessThanOrEqual(40, $this->queries($uri, $user), "{$uri} is too chatty");
    }
}
