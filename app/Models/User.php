<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'current_workspace_id'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Mirrors the column defaults so a freshly created user is complete without a refresh. */
    protected $attributes = [
        'is_admin' => false,
        'current_workspace_id' => null,
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
        'two_factor_confirmed_at' => null,
        'password_changed_at' => null,
        'last_login_at' => null,
        'locale' => null,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')->withPivot('role')->withTimestamps();
    }

    public function currentWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }

    /** The workspace to act in: the chosen one if still a member, else the first. */
    public function activeWorkspace(): ?Workspace
    {
        if ($this->current_workspace_id && ($ws = $this->workspaces()->whereKey($this->current_workspace_id)->first())) {
            return $ws;
        }
        $ws = $this->workspaces()->orderBy('workspaces.id')->first();
        if ($ws) {
            $this->forceFill(['current_workspace_id' => $ws->id])->save();
        }

        return $ws;
    }

    public function roleIn(Workspace $workspace): ?string
    {
        return $this->workspaces()->whereKey($workspace->id)->first()?->pivot->role;
    }

    public function canManage(Workspace $workspace): bool
    {
        return in_array($this->roleIn($workspace), ['owner', 'admin'], true);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }
}
