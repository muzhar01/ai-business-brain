<?php

namespace App\Brain\Contracts;

use App\Models\Conversation;

interface Tool
{
    /** snake_case name the model calls. */
    public function name(): string;

    /** What it does and when to use it. The model reads this. */
    public function description(): string;

    /** JSON Schema of the arguments (type: object). */
    public function parameters(): array;

    /** Changes the outside world? Then a human approves it first. */
    public function requiresApproval(): bool;

    /** Return a string or array; arrays are JSON-encoded for the model. */
    public function handle(array $arguments, ?Conversation $conversation = null): string|array;
}
