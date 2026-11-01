<?php

namespace App\Http\Controllers\Brain;

use App\Brain\Tools\BusinessReportTool;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Task;
use Illuminate\Http\Request;

class CrmController extends Controller
{
    public function leads(Request $request)
    {
        return Lead::query()->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->latest()->paginate(50);
    }

    public function tasks(Request $request)
    {
        return Task::query()->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->latest()->paginate(50);
    }

    public function report(Request $request, BusinessReportTool $report)
    {
        return $report->handle(['days' => (int) $request->query('days', 30)]);
    }
}
