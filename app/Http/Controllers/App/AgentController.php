<?php

namespace App\Http\Controllers\App;

use App\Brain\LlmManager;
use App\Brain\Tools\ToolRegistry;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Saas\Quota;
use App\Tenancy\Tenant;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    public function index()
    {
        return view('app.agents.index', ['agents' => Agent::orderByDesc('is_default')->orderBy('name')->get()]);
    }

    public function create(LlmManager $llm, ToolRegistry $tools)
    {
        return $this->form(new Agent(['tools' => null]), $llm, $tools);
    }

    public function edit(Agent $agent, LlmManager $llm, ToolRegistry $tools)
    {
        return $this->form($agent, $llm, $tools);
    }

    protected function form(Agent $agent, LlmManager $llm, ToolRegistry $tools)
    {
        return view('app.agents.form', ['agent' => $agent, 'providers' => $llm->available(), 'tools' => $tools->all()]);
    }

    public function store(Request $request)
    {
        Quota::ensure(Tenant::current(), 'agents');
        $agent = Agent::create($this->validated($request));
        $this->defaults($agent);
        AuditLog::record('agent.created', $agent);

        return redirect()->route('agents.index')->with('status', __('Agent :name created.', ['name' => $agent->name]));
    }

    public function update(Request $request, Agent $agent)
    {
        $agent->update($this->validated($request));
        $this->defaults($agent);
        AuditLog::record('agent.updated', $agent);

        return redirect()->route('agents.index')->with('status', __('Agent saved.'));
    }

    public function destroy(Agent $agent)
    {
        abort_if(Agent::count() <= 1, 422, 'Keep at least one agent.');
        $agent->delete();
        if (! Agent::where('is_default', true)->exists()) {
            Agent::orderBy('id')->first()?->update(['is_default' => true]);
        }

        return back()->with('status', __('Agent deleted.'));
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'description' => 'nullable|string|max:200',
            'instructions' => 'nullable|string|max:20000',
            'provider' => 'nullable|string|max:40',
            'model' => 'nullable|string|max:120',
            'temperature' => 'nullable|numeric|between:0,2',
            'collection' => 'nullable|string|max:100',
            'tools_mode' => 'required|in:all,custom',
            'tools' => 'nullable|array',
            'is_default' => 'nullable|boolean',
            'widget_enabled' => 'nullable|boolean',
            'widget_greeting' => 'nullable|string|max:200',
            'widget_color' => 'nullable|string|max:9',
        ]);
        $data['tools'] = $data['tools_mode'] === 'all' ? null : array_values($data['tools'] ?? []);
        unset($data['tools_mode']);
        $data['is_default'] = $request->boolean('is_default');
        $data['widget_enabled'] = $request->boolean('widget_enabled');

        return $data;
    }

    protected function defaults(Agent $agent): void
    {
        if ($agent->is_default) {
            Agent::whereKeyNot($agent->id)->update(['is_default' => false]);
        }
        if ($agent->widget_enabled) {
            Agent::whereKeyNot($agent->id)->update(['widget_enabled' => false]);
        }
    }
}
