<?php

namespace App\Brain\Data;

final class ToolCall
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $arguments = [],
        public readonly array $meta = [], // provider extras, e.g. Gemini thoughtSignature
    ) {}

    public static function fromArray(array $data): self
    {
        return new self($data['id'], $data['name'], $data['arguments'] ?? [], $data['meta'] ?? []);
    }

    public function toArray(): array
    {
        return array_filter(['id' => $this->id, 'name' => $this->name, 'arguments' => $this->arguments, 'meta' => $this->meta ?: null], fn ($v) => $v !== null);
    }
}
