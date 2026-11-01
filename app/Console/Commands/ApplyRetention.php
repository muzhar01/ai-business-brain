<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\AuthEvent;
use App\Models\Conversation;
use App\Models\Export;
use App\Models\WebhookDelivery;
use App\Models\Workspace;
use App\Tenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ApplyRetention extends Command
{
    protected $signature = 'brain:retention';

    protected $description = 'Enforce data-retention policies (per-workspace conversations, export files, delivery and auth logs)';

    public function handle(): int
    {
        $disk = Storage::disk(config('brain.knowledge.disk'));

        Workspace::lazyById()->each(function (Workspace $ws) use ($disk) {
            Tenant::run($ws, function () use ($ws, $disk) {
                if (($days = (int) $ws->setting('retention_days')) > 0) {
                    $n = 0;
                    Conversation::where('updated_at', '<', now()->subDays($days))->lazyById()->each(function ($c) use (&$n) {
                        $c->delete();
                        $n++;
                    });
                    if ($n) {
                        AuditLog::record('retention.purged', null, ['conversations' => $n, 'older_than_days' => $days]);
                    }
                }
                Export::where('created_at', '<', now()->subDays(7))->whereNotNull('path')->lazyById()->each(function ($e) use ($disk) {
                    $disk->delete($e->path);
                    $e->update(['path' => null, 'status' => 'expired']);
                });
                WebhookDelivery::where('created_at', '<', now()->subDays(30))->delete();
            });
        });

        AuthEvent::where('created_at', '<', now()->subDays(config('brain.security.auth_event_retention_days')))->delete();
        $this->info('Retention applied.');

        return self::SUCCESS;
    }
}
