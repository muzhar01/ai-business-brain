<?php

namespace App\Models;

use App\Security\Sealed;
use App\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    use BelongsToWorkspace;

    public const STATUSES = ['new', 'contacted', 'qualified', 'proposal', 'won', 'lost'];

    protected $table = 'brain_leads';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['value' => 'decimal:2', 'notes' => Sealed::class];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
