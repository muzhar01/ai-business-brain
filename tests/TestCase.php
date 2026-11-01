<?php

namespace Tests;

use App\Models\ApiToken;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        Tenant::forget();
        parent::tearDown();
    }

    /** A user with a Business-plan workspace (all features) set as current tenant. */
    protected function tenant(string $plan = 'business', ?User $user = null): array
    {
        $user ??= User::factory()->create();
        $ws = Workspace::createFor($user, 'Acme '.uniqid());
        $ws->update(['plan_override' => $plan]);
        Tenant::set($ws->fresh());
        [, $token] = ApiToken::issue($ws, 'test');

        return [$user, Tenant::current(), $token];
    }
}
