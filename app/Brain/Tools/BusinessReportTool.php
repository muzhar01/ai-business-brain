<?php

namespace App\Brain\Tools;

use App\Brain\Contracts\Tool;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Task;

class BusinessReportTool implements Tool
{
    public function name(): string
    {
        return 'business_report';
    }

    public function description(): string
    {
        return 'Live numbers from the CRM: pipeline by stage, won revenue, new leads, conversion rate, open and overdue tasks.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'days' => ['type' => 'integer', 'description' => 'Look-back window in days for "new" and "won" figures (default 30)'],
            ],
        ];
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function handle(array $arguments, ?Conversation $conversation = null): string|array
    {
        $days = max(1, (int) ($arguments['days'] ?? 30));
        $since = now()->subDays($days);

        $byStage = Lead::query()->selectRaw('status, COUNT(*) as count, COALESCE(SUM(value),0) as value')
            ->groupBy('status')->get()->keyBy('status')
            ->map(fn ($r) => ['count' => (int) $r->count, 'value' => (float) $r->value]);

        $closed = Lead::whereIn('status', ['won', 'lost'])->count();

        return [
            'window_days' => $days,
            'pipeline_by_stage' => $byStage,
            'open_pipeline_value' => (float) Lead::whereNotIn('status', ['won', 'lost'])->sum('value'),
            'new_leads' => Lead::where('created_at', '>=', $since)->count(),
            'won_in_window' => [
                'count' => Lead::where('status', 'won')->where('updated_at', '>=', $since)->count(),
                'value' => (float) Lead::where('status', 'won')->where('updated_at', '>=', $since)->sum('value'),
            ],
            'win_rate' => $closed ? round(Lead::where('status', 'won')->count() / $closed * 100, 1).'%' : 'n/a',
            'tasks' => [
                'open' => Task::where('status', 'open')->count(),
                'overdue' => Task::where('status', 'open')->whereDate('due_date', '<', today())->count(),
                'done_in_window' => Task::where('status', 'done')->where('updated_at', '>=', $since)->count(),
            ],
        ];
    }
}
