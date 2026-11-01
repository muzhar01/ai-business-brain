<?php

namespace App\Jobs;

use App\Models\Workspace;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/** Base for queued work that must run inside a workspace. */
abstract class TenantJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $timeout = 1800;

    public function __construct(public int $workspaceId) {}

    public function handle(): void
    {
        $workspace = Workspace::find($this->workspaceId);
        if ($workspace && ! $workspace->suspended) {
            Tenant::run($workspace, fn () => app()->call([$this, 'process']));
        }
    }
}
