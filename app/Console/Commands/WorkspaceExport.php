<?php

namespace App\Console\Commands;

use App\Etl\WorkspaceBackup;
use App\Models\Workspace;
use Illuminate\Console\Command;

class WorkspaceExport extends Command
{
    protected $signature = 'brain:workspace:export {workspace : id or slug} {path? : output .zip}';

    protected $description = 'Back up a whole workspace to one portable .zip (move it to another server or database)';

    public function handle(WorkspaceBackup $backup): int
    {
        $ws = Workspace::where('id', $this->argument('workspace'))->orWhere('slug', $this->argument('workspace'))->firstOrFail();
        $this->info('Written: '.$backup->export($ws, $this->argument('path')));

        return self::SUCCESS;
    }
}
