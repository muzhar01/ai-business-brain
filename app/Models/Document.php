<?php

namespace App\Models;

use App\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    use BelongsToWorkspace;

    protected $table = 'brain_documents';

    protected $guarded = ['id'];

    protected $hidden = ['meta'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }
}
