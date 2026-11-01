<?php

namespace App\Console\Commands;

use App\Etl\WorkspaceBackup;
use App\Models\User;
use Illuminate\Console\Command;

class WorkspaceImport extends Command
{
    protected $signature = 'brain:workspace:import {file : backup .zip} {--owner= : owner email (user must exist)}';

    protected $description = 'Restore a workspace backup as a new workspace';

    public function handle(WorkspaceBackup $backup): int
    {
        $owner = $this->option('owner') ? User::where('email', $this->option('owner'))->firstOrFail() : null;
        $ws = $backup->import($this->argument('file'), $owner);
        $this->info("Restored as workspace #{$ws->id} ({$ws->name})");

        return self::SUCCESS;
    }
}
