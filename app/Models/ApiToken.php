<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    /** @return array{0: self, 1: string} the model and the plain token (shown once) */
    public static function issue(Workspace $workspace, string $name, ?User $user = null): array
    {
        $plain = 'brn_'.Str::random(40);
        $token = self::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user?->id,
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'prefix' => substr($plain, 0, 10),
        ]);

        return [$token, $plain];
    }

    public static function findByPlain(?string $plain): ?self
    {
        return $plain ? self::with('workspace')->where('token_hash', hash('sha256', $plain))->first() : null;
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
