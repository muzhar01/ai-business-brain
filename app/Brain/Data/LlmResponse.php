<?php

namespace App\Brain\Data;

final class LlmResponse
{
    /** @param array<int, ToolCall> $toolCalls */
    public function __construct(
        public readonly ?string $content,
        public readonly array $toolCalls = [],
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly ?string $model = null,
        public readonly ?string $finishReason = null,
        public readonly array $raw = [],
    ) {}

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
