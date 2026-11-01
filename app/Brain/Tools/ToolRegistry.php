<?php

namespace App\Brain\Tools;

use App\Brain\Contracts\Tool;
use App\Models\Conversation;
use App\Models\PendingAction;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ToolRegistry
{
    /** @var array<string, Tool> */
    protected array $tools = [];

    public function register(Tool $tool): static
    {
        $this->tools[$tool->name()] = $tool;

        return $this;
    }

    /** @return array<string, Tool> */
    public function all(?array $only = null): array
    {
        $tools = array_filter($this->tools, fn (Tool $t) => ! method_exists($t, 'enabled') || $t->enabled());

        return $only === null ? $tools : array_intersect_key($tools, array_flip($only));
    }

    public function get(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }

    /** Runs a tool call from the model. Never throws: errors go back to the model as text. */
    public function execute(string $name, array $arguments, ?Conversation $conversation = null): string
    {
        $tool = $this->get($name);
        if (! $tool) {
            return "Error: unknown tool [{$name}].";
        }

        if ($error = $this->validate($tool, $arguments)) {
            return "Error: invalid arguments for {$name}: {$error}";
        }

        $requireApproval = Tenant::current()?->setting('require_approval') ?? config('brain.tools.require_approval');
        if ($tool->requiresApproval() && $requireApproval) {
            $action = PendingAction::create([
                'conversation_id' => $conversation?->id,
                'tool' => $name,
                'arguments' => $arguments,
            ]);

            return "Queued for human approval as action #{$action->id}. It has NOT happened yet. Tell the user it is waiting for approval.";
        }

        return $this->run($tool, $arguments, $conversation);
    }

    public function run(Tool $tool, array $arguments, ?Conversation $conversation = null): string
    {
        try {
            $result = $tool->handle($arguments, $conversation);

            return is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        } catch (Throwable $e) {
            report($e);

            return "Error running {$tool->name()}: {$e->getMessage()}";
        }
    }

    /** Light JSON-schema check: required keys and enums. */
    protected function validate(Tool $tool, array $arguments): ?string
    {
        $schema = $tool->parameters();
        $rules = [];
        foreach ($schema['properties'] ?? [] as $key => $prop) {
            $rule = in_array($key, $schema['required'] ?? [], true) ? ['required'] : ['nullable'];
            if (isset($prop['enum'])) {
                $rule[] = 'in:'.implode(',', $prop['enum']);
            }
            $rules[$key] = $rule;
        }

        $validator = Validator::make($arguments, $rules);

        return $validator->fails() ? $validator->errors()->first() : null;
    }
}
