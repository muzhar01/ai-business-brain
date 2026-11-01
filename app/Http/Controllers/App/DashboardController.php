<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Lead;
use App\Models\Message;
use App\Models\PendingAction;
use App\Models\Task;
use App\Saas\Quota;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $ws = Tenant::current();
        $since = now()->subDays(29)->startOfDay();

        $perDay = Message::where('role', 'user')->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')->groupBy('d')->pluck('c', 'd');
        $days = collect(range(29, 0))->map(fn ($i) => now()->subDays($i)->toDateString())
            ->mapWithKeys(fn ($d) => [$d => (int) ($perDay[$d] ?? 0)]);

        $tools = Message::where('role', 'tool')->where('created_at', '>=', $since)
            ->select('tool_name', DB::raw('COUNT(*) as c'))->groupBy('tool_name')->orderByDesc('c')->limit(6)->pluck('c', 'tool_name');

        $ratings = Message::where('role', 'assistant')->whereNotNull('rating')->where('created_at', '>=', $since)
            ->selectRaw('SUM(CASE WHEN rating > 0 THEN 1 ELSE 0 END) as up, SUM(CASE WHEN rating < 0 THEN 1 ELSE 0 END) as down')->first();

        return view('app.dashboard', [
            'quota' => Quota::summary($ws),
            'days' => $days,
            'tools' => $tools,
            'ratings' => $ratings,
            'channels' => Conversation::where('created_at', '>=', $since)->select('channel', DB::raw('COUNT(*) as c'))->groupBy('channel')->pluck('c', 'channel'),
            'stats' => [
                'conversations' => Conversation::count(),
                'documents' => Document::count(),
                'leads' => Lead::count(),
                'pipeline' => (float) Lead::whereNotIn('status', ['won', 'lost'])->sum('value'),
                'won' => (float) Lead::where('status', 'won')->sum('value'),
                'open_tasks' => Task::where('status', 'open')->count(),
                'overdue' => Task::where('status', 'open')->whereDate('due_date', '<', today())->count(),
                'pending' => PendingAction::where('status', 'pending')->count(),
            ],
            'recent' => Conversation::latest('updated_at')->limit(6)->get(),
        ]);
    }
}
