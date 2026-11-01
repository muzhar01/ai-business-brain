<?php

namespace App\Models;

use App\Saas\Plan;
use App\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Laravel\Cashier\Billable;

class Workspace extends Model
{
    use Billable;

    protected $guarded = ['id'];

    protected $hidden = ['provider_keys', 'telegram_bot_token', 'telegram_secret', 'stripe_id', 'whatsapp', 'slack'];

    public const SETTINGS = [
        'business_name' => null,
        'business_description' => '',
        'tone' => 'professional, warm and concise',
        'instructions' => '',
        'default_provider' => null,
        'embedding_provider' => null,
        'require_approval' => true,
        'webhook_url' => null,
        'timezone' => 'UTC',
        'language' => null,
        'briefing_enabled' => false,
        'briefing_emails' => '',
        'briefing_hour' => 8,
        'brand_color' => '#7c9cff',
        'hipaa_mode' => false,
        'require_2fa' => false,
        'session_timeout' => 0,
        'retention_days' => 0,
        'privacy_notice' => null,
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'provider_keys' => 'encrypted:array',
            'telegram_bot_token' => 'encrypted',
            'whatsapp' => 'encrypted:array',
            'slack' => 'encrypted:array',
            'suspended' => 'boolean',
            'trial_ends_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn (self $ws) => $ws->resolvedPlan = null);
        static::creating(function (self $ws) {
            $ws->uuid ??= (string) Str::ulid();
            $ws->slug ??= Str::slug($ws->name).'-'.Str::lower(Str::random(5));
            $ws->widget_key ??= 'wk_'.Str::random(32);
            $ws->settings = array_merge(self::SETTINGS, ['business_name' => $ws->name], $ws->settings ?? []);
        });
    }

    public static function createFor(User $owner, string $name): self
    {
        $ws = self::create([
            'name' => $name,
            'owner_id' => $owner->id,
            'trial_ends_at' => config('saas.trial_days') ? now()->addDays(config('saas.trial_days')) : null,
        ]);
        $ws->members()->attach($owner->id, ['role' => 'owner']);
        $owner->forceFill(['current_workspace_id' => $ws->id])->save();

        Tenant::run($ws, fn () => Agent::create([
            'name' => 'Business Assistant',
            'description' => 'General assistant for your team',
            'is_default' => true,
        ]));

        return $ws;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')->withPivot('role')->withTimestamps();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? self::SETTINGS[$key] ?? $default;
    }

    public function updateSettings(array $values): void
    {
        $this->settings = array_merge($this->settings ?? [], $values);
        $this->save();
    }

    /** Active plan: admin override > Stripe subscription > trial (pro) > free. */
    protected ?Plan $resolvedPlan = null;

    /** Memoised per instance: plan() runs on every request, quota check and tool call. */
    public function plan(): Plan
    {
        if ($this->resolvedPlan && ! $this->isDirty('plan_override')) { // re-resolve after unsaved override edits
            return $this->resolvedPlan;
        }

        return $this->resolvedPlan = $this->resolvePlan();
    }

    public function refresh()
    {
        $this->resolvedPlan = null;

        return parent::refresh();
    }

    protected function resolvePlan(): Plan
    {
        if ($this->plan_override) {
            return Plan::find($this->plan_override);
        }

        $subscription = $this->relationLoaded('subscriptions')
            ? $this->subscriptions->first(fn ($s) => $s->type === 'default' && $s->valid())
            : $this->subscription('default');

        if ($subscription && $subscription->valid() && ($plan = Plan::forStripePrice($subscription->stripe_price))) {
            return $plan;
        }

        if ($this->onGenericTrial()) {
            return Plan::find('pro');
        }

        return Plan::find(config('saas.default_plan'));
    }

    public function hipaa(): bool
    {
        return (bool) $this->setting('hipaa_mode');
    }

    /** Public channels (widget, Telegram, WhatsApp) are off in HIPAA mode: no BAA with those carriers. */
    public function allowsPublicChannel(string $feature): bool
    {
        return ! $this->suspended && ! $this->hipaa() && $this->plan()->has($feature);
    }

    public function providerKey(string $provider): ?array
    {
        return ($this->provider_keys ?? [])[$provider] ?? null;
    }

    /** Stripe customer email = owner's. */
    public function stripeEmail(): ?string
    {
        return $this->owner?->email;
    }

    public function stripeName(): ?string
    {
        return $this->name;
    }
}
