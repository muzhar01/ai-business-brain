<?php

namespace App\Models;

use App\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    use BelongsToWorkspace;

    protected $table = 'brain_agents';

    protected $guarded = ['id', 'workspace_id'];

    protected function casts(): array
    {
        return ['tools' => 'array', 'is_default' => 'boolean', 'widget_enabled' => 'boolean', 'temperature' => 'float'];
    }

    public static function default(): ?self
    {
        return self::where('is_default', true)->first() ?? self::orderBy('id')->first();
    }
}
