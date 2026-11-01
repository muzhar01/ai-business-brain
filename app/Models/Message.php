<?php

namespace App\Models;

use App\Security\Sealed;
use App\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use BelongsToWorkspace;

    protected $table = 'brain_messages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tool_calls' => 'array', 'sources' => 'array', 'content' => Sealed::class];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** Back to the normalized driver shape. */
    public function toLlm(): array
    {
        return match ($this->role) {
            'assistant' => ['role' => 'assistant', 'content' => $this->content, 'tool_calls' => $this->tool_calls ?? []],
            'tool' => ['role' => 'tool', 'tool_call_id' => $this->tool_call_id, 'name' => $this->tool_name, 'content' => (string) $this->content],
            default => ['role' => $this->role, 'content' => (string) $this->content],
        };
    }
}
