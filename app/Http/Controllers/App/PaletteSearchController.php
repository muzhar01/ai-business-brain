<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaletteSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 60));
        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }
        $like = '%'.addcslashes($q, '\\%_').'%';

        $leads = Lead::query()->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('company', 'like', $like))
            ->latest()->limit(5)->get(['id', 'name', 'company'])
            ->map(fn ($l) => ['type' => 'lead', 'label' => trim($l->name.($l->company ? ' · '.$l->company : '')), 'href' => route('leads.index', ['q' => $l->name], false)]);
        $tasks = Task::query()->where('title', 'like', $like)->latest()->limit(5)->get(['id', 'title'])
            ->map(fn ($t) => ['type' => 'task', 'label' => $t->title, 'href' => route('tasks.index', [], false)]);

        return response()->json(['results' => $leads->concat($tasks)->values()]);
    }
}
