<?php

namespace App\Models;

use App\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingAction extends Model
{
    use BelongsToWorkspace;

    protected $table = 'brain_pending_actions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['arguments' => 'array', 'decided_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
