<?php

namespace App\Tenancy;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Row-level multi-tenancy: every query is filtered to the current workspace
 * and every insert is stamped with it. Use Model::withoutGlobalScope('workspace')
 * only in super-admin code.
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope('workspace', function (Builder $query) {
            if ($id = Tenant::id()) {
                $query->where($query->getModel()->qualifyColumn('workspace_id'), $id);
            }
        });

        static::creating(function ($model) {
            if (! $model->workspace_id && Tenant::id()) {
                $model->workspace_id = Tenant::id();
            }
            if (! $model->workspace_id) {
                throw new \LogicException(static::class.' created without a workspace.');
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
