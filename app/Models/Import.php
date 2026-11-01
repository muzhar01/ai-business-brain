<?php

namespace App\Models;

use App\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Import extends Model
{
    use BelongsToWorkspace;

    protected $table = 'brain_imports';

    protected $guarded = ['id', 'workspace_id'];

    protected $hidden = ['source'];

    protected function casts(): array
    {
        return [
            'source' => 'encrypted:array',
            'columns' => 'array',
            'mapping' => 'array',
            'options' => 'array',
            'errors' => 'array',
            'last_run_at' => 'datetime',
        ];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return ($this->options ?? [])[$key] ?? $default;
    }
}
