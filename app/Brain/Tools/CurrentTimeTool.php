<?php

namespace App\Brain\Tools;

use App\Brain\Contracts\Tool;
use App\Models\Conversation;

class CurrentTimeTool implements Tool
{
    public function name(): string
    {
        return 'current_datetime';
    }

    public function description(): string
    {
        return 'Get the current date, time, weekday and timezone. Use for any "today", "this week" or deadline math.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function handle(array $arguments, ?Conversation $conversation = null): string|array
    {
        $now = now();

        return ['datetime' => $now->toIso8601String(), 'weekday' => $now->format('l'), 'timezone' => $now->timezoneName];
    }
}
