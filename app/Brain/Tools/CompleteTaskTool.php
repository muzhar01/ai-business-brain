<?php

namespace App\Brain\Tools;

use App\Brain\Contracts\Tool;
use App\Models\Conversation;
use App\Models\Task;

class CompleteTaskTool implements Tool
{
    public function name(): string
    {
        return 'complete_task';
    }

    public function description(): string
    {
        return 'Mark a task as done.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']];
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function handle(array $arguments, ?Conversation $conversation = null): string|array
    {
        $task = Task::find($arguments['id']);
        if (! $task) {
            return "Task #{$arguments['id']} not found.";
        }
        $task->update(['status' => 'done']);

        return ['completed' => true, 'task' => $task->toArray()];
    }
}
