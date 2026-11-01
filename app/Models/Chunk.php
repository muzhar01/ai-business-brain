<?php

namespace App\Models;

use App\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Chunk extends Model
{
    use BelongsToWorkspace;

    protected $table = 'brain_chunks';

    protected $guarded = ['id'];

    protected $hidden = ['embedding'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
