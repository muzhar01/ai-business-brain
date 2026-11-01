<?php

namespace App\Brain\Tools;

use App\Brain\Contracts\Tool;
use App\Models\Conversation;
use App\Models\Task;

class ListTasksTool implements Tool
{
    public function name(): string
    {
        return 'list_tasks';
    }

    public function description(): string
    {
        return 'List tasks, e.g. open tasks, overdue tasks, or tasks for one person.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['open', 'done']],
                'assignee' => ['type' => 'string'],
                'overdue_only' => ['type' => 'boolean'],
            ],
        ];
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function handle(array $arguments, ?Conversation $conversation = null): string|array
    {
        $tasks = Task::query()
            ->when($arguments['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($arguments['assignee'] ?? null, fn ($q, $a) => $q->where('assignee', 'like', "%{$a}%"))
            ->when(! empty($arguments['overdue_only']), fn ($q) => $q->where('status', 'open')->whereDate('due_date', '<', today()))
            ->orderByRaw('due_date IS NULL, due_date')->limit(50)->get();

        return $tasks->isEmpty() ? 'No tasks found.' : $tasks->toArray();
    }
}
