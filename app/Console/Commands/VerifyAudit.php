<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;

class VerifyAudit extends Command
{
    protected $signature = 'brain:audit:verify';

    protected $description = 'Verify the tamper-evident audit-log hash chain of every workspace';

    public function handle(): int
    {
        $broken = 0;
        foreach (AuditLog::query()->distinct()->pluck('workspace_id') as $ws) {
            if ($id = AuditLog::verifyChain($ws)) {
                $this->error("Workspace {$ws}: chain broken at audit log #{$id}");
                $broken++;
            }
        }
        $broken ? $this->error("{$broken} broken chain(s).") : $this->info('All audit chains intact.');

        return $broken ? self::FAILURE : self::SUCCESS;
    }
}
