<?php

namespace App\Models;

use App\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

class Export extends Model
{
    use BelongsToWorkspace;

    protected $table = 'brain_exports';

    protected $guarded = ['id', 'workspace_id'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'filters' => 'array'];
    }
}
