<?php

namespace App\Console\Commands;

use App\Brain\Brain;
use App\Mail\DailyBriefing;
use App\Models\Workspace;
use App\Support\Locale;
use App\Tenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendBriefings extends Command
{
    protected $signature = 'brain:briefings {--force : Send now regardless of each workspace\'s hour}';

    protected $description = 'Email each workspace its daily AI business briefing (runs hourly from the scheduler)';

    public const PROMPT = 'Write my daily business briefing. Use business_report (last 1 day and last 30 days) and list_tasks (overdue_only and open). Include: key numbers, what changed, overdue tasks, hot leads to follow up, and 3 concrete actions for today. Plain text, short sections, no markdown tables.';

    public function handle(Brain $brain): int
    {
        Workspace::where('suspended', false)->lazyById()->each(function (Workspace $ws) use ($brain) {
            if (! $ws->setting('briefing_enabled') || ! $ws->plan()->has('briefings') || blank($ws->setting('briefing_emails'))) {
                return;
            }
            if (! $this->option('force') && (int) now($ws->setting('timezone') ?: 'UTC')->format('G') !== (int) $ws->setting('briefing_hour', 8)) {
                return;
            }

            Tenant::run($ws, function () use ($ws, $brain) {
                try {
                    $result = $brain->ask(self::PROMPT, null, null, ['channel' => 'app', 'user_ref' => 'briefing', 'tools' => ['business_report', 'list_tasks', 'find_leads', 'current_datetime', 'query_dataset']]);
                    $emails = array_filter(array_map('trim', preg_split('/[,;\s]+/', $ws->setting('briefing_emails'))), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
                    Mail::to($emails)->send((new DailyBriefing($ws->name, $result['message']->content))->locale(Locale::match($ws->owner?->locale) ?? Locale::match(config('app.locale')) ?? 'en'));
                    $this->info("Briefing sent for {$ws->name}");
                } catch (Throwable $e) {
                    report($e);
                    $this->error("{$ws->name}: {$e->getMessage()}");
                }
            });
        });

        return self::SUCCESS;
    }
}
