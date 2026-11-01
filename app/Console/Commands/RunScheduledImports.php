<?php

namespace App\Console\Commands;

use App\Jobs\RunImport;
use App\Models\Import;
use Illuminate\Console\Command;

class RunScheduledImports extends Command
{
    protected $signature = 'brain:etl:scheduled';

    protected $description = 'Queue recurring ETL imports (URL / database syncs) that are due';

    public function handle(): int
    {
        Import::withoutGlobalScope('workspace')->whereNotNull('schedule')->whereIn('source_type', ['url', 'database'])
            ->whereNotIn('status', ['queued', 'running'])->with('workspace')->lazyById()
            ->each(function (Import $import) {
                $due = match ($import->schedule) {
                    'hourly' => now()->subMinutes(55),
                    'daily' => now()->subHours(23)->subMinutes(30),
                    'weekly' => now()->subDays(7)->addMinutes(30),
                    default => null,
                };
                if (! $due || ! $import->workspace || ! $import->workspace->plan()->has('scheduled_imports')) {
                    return;
                }
                if ($import->last_run_at === null || $import->last_run_at->lte($due)) {
                    $import->forceFill(['status' => 'queued'])->save();
                    RunImport::dispatch($import->workspace_id, $import->id);
                    $this->info("Queued import #{$import->id} {$import->name}");
                }
            });

        return self::SUCCESS;
    }
}
