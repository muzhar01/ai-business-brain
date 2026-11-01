<?php

namespace App\Security;

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as ProviderUser;

/**
 * Single sign-on via OAuth/OIDC (Google, Microsoft Entra ID, GitHub).
 * Resolution order: linked identity → existing user with the same verified email →
 * workspace that claims the email domain (auto-join) → new user with their own workspace.
 */
class Sso
{
    public const PROVIDERS = [
        'google' => 'Google',
        'microsoft' => 'Microsoft',
        'github' => 'GitHub',
    ];

    /** @return array<string, string> providers with credentials configured */
    public static function enabled(): array
    {
        return array_filter(self::PROVIDERS, fn ($label, $key) => filled(config("services.{$key}.client_id")), ARRAY_FILTER_USE_BOTH);
    }

    public function resolve(string $provider, ProviderUser $identity): User
    {
        $account = SocialAccount::where('provider', $provider)->where('provider_id', (string) $identity->getId())->first();
        if ($account) {
            return $account->user;
        }

        $email = Str::lower((string) $identity->getEmail());
        abort_if($email === '' || ! $this->verified($provider, $identity), 403, 'Your '.self::PROVIDERS[$provider].' account has no verified email address.');

        $user = User::where('email', $email)->first();

        if (! $user) {
            $user = User::create([
                'name' => $identity->getName() ?: Str::before($email, '@'),
                'email' => $email,
                'password' => null,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $domain = Str::after($email, '@');
            $workspace = Workspace::all()->first(fn (Workspace $w) => Str::lower((string) $w->setting('sso_domain')) === $domain);

            if ($workspace) {
                $workspace->members()->attach($user->id, ['role' => $workspace->setting('sso_default_role', 'member')]);
                $user->forceFill(['current_workspace_id' => $workspace->id])->save();
            } else {
                Workspace::createFor($user, Str::before($domain, '.') ? Str::title(Str::before($domain, '.')) : $user->name);
            }
        }

        $user->socialAccounts()->create(['provider' => $provider, 'provider_id' => (string) $identity->getId(), 'email' => $email]);

        return $user;
    }

    protected function verified(string $provider, ProviderUser $identity): bool
    {
        $raw = method_exists($identity, 'getRaw') ? $identity->getRaw() : [];

        return match ($provider) {
            'google' => (bool) ($raw['email_verified'] ?? $raw['verified_email'] ?? false),
            default => true, // GitHub returns the primary verified email; Entra ID emails are tenant-managed
        };
    }

    /** Password logins are refused when any of the user's workspaces enforces SSO (owners keep break-glass access). */
    public static function passwordBlocked(User $user): bool
    {
        return $user->workspaces->contains(fn (Workspace $w) => $w->setting('enforce_sso') && $w->owner_id !== $user->id);
    }
}
