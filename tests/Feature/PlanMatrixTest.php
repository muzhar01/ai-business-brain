<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Dataset;
use App\Models\Document;
use App\Models\Usage;
use App\Saas\FeatureUnavailable;
use App\Saas\Plan;
use App\Saas\Quota;
use App\Saas\QuotaExceeded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Every plan × every limit at its boundary, and every plan × every feature gate. */
class PlanMatrixTest extends TestCase
{
    use RefreshDatabase;

    public const FEATURES = ['export', 'api', 'widget', 'telegram', 'whatsapp', 'webhooks', 'briefings', 'scheduled_imports', 'url_imports', 'database_imports', 'audit_log', 'white_label', 'hipaa'];

    public static function features(): iterable
    {
        foreach (['free', 'pro', 'business', 'enterprise'] as $plan) {
            foreach (self::FEATURES as $f) {
                yield "{$plan} {$f}" => [$plan, $f];
            }
        }
    }

    #[DataProvider('features')]
    public function test_feature_gate(string $plan, string $feature): void
    {
        [, $ws] = $this->tenant($plan);
        $has = in_array($feature, config("saas.plans.{$plan}.features"), true);
        $this->assertSame($has, $ws->plan()->has($feature));
        if (! $has) {
            $this->expectException(FeatureUnavailable::class);
        }
        Quota::feature($ws, $feature);
        $this->addToAssertionCount(1);
    }

    public static function limits(): iterable
    {
        foreach (['free', 'pro', 'business'] as $plan) {
            foreach (['messages', 'import_rows', 'documents', 'agents', 'datasets', 'members'] as $limit) {
                foreach (['below' => -1, 'at' => 0] as $label => $offset) {
                    yield "{$plan} {$limit} {$label}" => [$plan, $limit, $offset];
                }
            }
        }
    }

    #[DataProvider('limits')]
    public function test_limit_boundary(string $plan, string $limit, int $offset): void
    {
        config(["saas.plans.{$plan}.limits.{$limit}" => 3]);
        [, $ws] = $this->tenant($plan);
        $used = 3 + $offset;

        match ($limit) {
            'messages', 'import_rows' => Usage::current($ws)->update([$limit => $used]),
            'documents' => collect(range(1, $used))->each(fn ($i) => Document::create(['title' => "d{$i}", 'collection' => 'default', 'source_type' => 'text', 'status' => 'ready'])),
            'agents' => collect(range(1, max(0, $used - 1)))->each(fn ($i) => Agent::create(['name' => "a{$i}"])), // +1 default agent
            'datasets' => collect(range(1, $used))->each(fn ($i) => Dataset::create(['name' => "ds{$i}"])),
            'members' => collect(range(1, max(0, $used - 1)))->each(fn ($i) => $ws->invitations()->create(['email' => "i{$i}@x.co", 'token' => "t{$i}", 'role' => 'member'])),
        };

        $this->assertSame($used, Quota::used($ws->fresh(), $limit));
        if ($offset === 0) {
            $this->expectException(QuotaExceeded::class);
        }
        Quota::ensure($ws->fresh(), $limit);
        $this->addToAssertionCount(1);
    }

    public static function unlimited(): iterable
    {
        foreach (['messages', 'import_rows', 'documents', 'agents', 'datasets', 'members'] as $limit) {
            yield $limit => [$limit];
        }
    }

    #[DataProvider('unlimited')]
    public function test_enterprise_is_unlimited(string $limit): void
    {
        [, $ws] = $this->tenant('enterprise');
        Usage::current($ws)->update(['messages' => 10_000_000, 'import_rows' => 10_000_000]);
        Quota::ensure($ws, $limit, 1_000_000);
        $this->assertSame(-1, $ws->plan()->limit($limit));
    }

    public function test_plan_resolution_order(): void
    {
        [, $ws] = $this->tenant('pro');
        $this->assertSame('pro', $ws->plan()->key);
        $ws->update(['plan_override' => null, 'trial_ends_at' => now()->addDay()]);
        $this->assertSame('pro', $ws->fresh()->plan()->key, 'trial = pro');
        $ws->update(['trial_ends_at' => now()->subDay()]);
        $this->assertSame('free', $ws->fresh()->plan()->key, 'expired trial = free');
        $this->assertSame('free', Plan::find('does-not-exist')->key);
    }
}
