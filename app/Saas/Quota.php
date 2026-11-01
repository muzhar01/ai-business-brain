<?php

namespace App\Saas;

use App\Models\Agent;
use App\Models\Dataset;
use App\Models\Document;
use App\Models\Usage;
use App\Models\Workspace;

/** Enforces plan limits. Call before the action; record after it. */
class Quota
{
    public static function ensure(Workspace $workspace, string $limit, int $adding = 1): void
    {
        $max = $workspace->plan()->limit($limit);
        if ($max < 0) {
            return;
        }

        $used = static::used($workspace, $limit);
        if ($used + $adding > $max) {
            throw new QuotaExceeded($limit === 'import_rows' ? 'imported rows this month' : str_replace('_', ' ', $limit).($limit === 'messages' ? ' this month' : ''), $max);
        }
    }

    public static function used(Workspace $workspace, string $limit): int
    {
        return match ($limit) {
            'messages' => (int) Usage::current($workspace)->messages,
            'import_rows' => (int) Usage::current($workspace)->import_rows,
            'documents' => Document::withoutGlobalScope('workspace')->where('workspace_id', $workspace->id)->count(),
            'agents' => Agent::withoutGlobalScope('workspace')->where('workspace_id', $workspace->id)->count(),
            'datasets' => Dataset::withoutGlobalScope('workspace')->where('workspace_id', $workspace->id)->count(),
            'members' => $workspace->members()->count() + $workspace->invitations()->whereNull('accepted_at')->count(),
            default => 0,
        };
    }

    public static function feature(Workspace $workspace, string $feature): void
    {
        if (! $workspace->plan()->has($feature)) {
            throw new FeatureUnavailable($feature);
        }
    }

    /** Usage summary for dashboards: [limit => [used, max]] */
    public static function summary(Workspace $workspace): array
    {
        return collect(array_keys($workspace->plan()->config['limits']))
            ->mapWithKeys(fn ($k) => [$k => ['used' => static::used($workspace, $k), 'max' => $workspace->plan()->limit($k)]])
            ->all();
    }
}
