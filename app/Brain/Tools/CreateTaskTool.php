<?php

namespace App\Brain\Tools;

use App\Brain\Contracts\Tool;
use App\Models\Conversation;
use App\Models\Task;

class CreateTaskTool implements Tool
{
    public function name(): string
    {
        return 'create_task';
    }

    public function description(): string
    {
        return 'Create a to-do / follow-up task for the team, optionally linked to a lead.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'due_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'priority' => ['type' => 'string', 'enum' => ['low', 'normal', 'high', 'urgent']],
                'assignee' => ['type' => 'string'],
                'lead_id' => ['type' => 'integer'],
            ],
            'required' => ['title'],
        ];
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function handle(array $arguments, ?Conversation $conversation = null): string|array
    {
        $task = Task::create(collect($arguments)->only(['title', 'description', 'due_date', 'priority', 'assignee', 'lead_id'])->filter()->all());

        return ['created' => true, 'task' => $task->toArray()];
    }
}
