<?php

namespace App\Models;

use App\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Any table a business wants the brain to know: products, inventory, orders, prices... */
class Dataset extends Model
{
    use BelongsToWorkspace;

    protected $table = 'brain_datasets';

    protected $guarded = ['id', 'workspace_id'];

    protected function casts(): array
    {
        return ['fields' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $d) => $d->slug ??= Str::slug($d->name, '_'));
    }

    public function rows(): HasMany
    {
        return $this->hasMany(DatasetRow::class);
    }

    public function fieldNames(): array
    {
        return collect($this->fields ?? [])->pluck('name')->all();
    }

    /** Learn new columns as data arrives. */
    public function rememberFields(array $names): void
    {
        $known = $this->fieldNames();
        $new = array_values(array_diff($names, $known));
        if ($new) {
            $this->fields = array_merge($this->fields ?? [], array_map(fn ($n) => ['name' => $n, 'type' => 'string'], $new));
            $this->save();
        }
    }
}
