<?php

namespace App\Brain\Data;

/**
 * Normalized message shape shared by every driver:
 *   ['role' => 'system'|'user'|'assistant'|'tool',
 *    'content' => ?string,
 *    'tool_calls' => [ToolCall-as-array, ...],   // assistant only
 *    'tool_call_id' => string, 'name' => string] // tool only
 */
final class Message
{
    public static function system(string $content): array
    {
        return ['role' => 'system', 'content' => $content];
    }

    public static function user(string $content): array
    {
        return ['role' => 'user', 'content' => $content];
    }

    /** @param array<int, ToolCall> $toolCalls */
    public static function assistant(?string $content, array $toolCalls = []): array
    {
        return [
            'role' => 'assistant',
            'content' => $content,
            'tool_calls' => array_map(fn (ToolCall $c) => $c->toArray(), $toolCalls),
        ];
    }

    public static function tool(string $toolCallId, string $name, string $content): array
    {
        return ['role' => 'tool', 'tool_call_id' => $toolCallId, 'name' => $name, 'content' => $content];
    }
}
