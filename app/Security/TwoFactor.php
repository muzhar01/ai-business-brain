<?php

namespace App\Security;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/** TOTP (RFC 6238) two-factor auth with one-time recovery codes. */
class TwoFactor
{
    public function __construct(protected Google2FA $engine) {}

    public function begin(User $user): string
    {
        $secret = $this->engine->generateSecretKey(32);
        $user->forceFill(['two_factor_secret' => encrypt($secret), 'two_factor_confirmed_at' => null])->save();

        return $secret;
    }

    public function qrSvg(User $user): string
    {
        $url = $this->engine->getQRCodeUrl(config('saas.name'), $user->email, decrypt($user->two_factor_secret));

        return (new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd)))->writeString($url);
    }

    /** @return array<int, string> plain recovery codes, shown once */
    public function confirm(User $user, string $code): ?array
    {
        if (! $user->two_factor_secret || ! $this->verifyTotp($user, $code)) {
            return null;
        }
        $codes = collect(range(1, 8))->map(fn () => Str::upper(Str::random(5).'-'.Str::random(5)))->all();
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => encrypt(array_map(fn ($c) => hash('sha256', $c), $codes)),
        ])->save();

        return $codes;
    }

    public function verify(User $user, string $code): bool
    {
        $code = trim($code);
        if ($this->verifyTotp($user, $code)) {
            return true;
        }

        // one-time recovery code
        $hashes = $user->two_factor_recovery_codes ? decrypt($user->two_factor_recovery_codes) : [];
        $hash = hash('sha256', Str::upper($code));
        if (in_array($hash, $hashes, true)) {
            $user->forceFill(['two_factor_recovery_codes' => encrypt(array_values(array_diff($hashes, [$hash])))])->save();

            return true;
        }

        return false;
    }

    public function disable(User $user): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
    }

    public function currentCode(User $user): string
    {
        return $this->engine->getCurrentOtp(decrypt($user->two_factor_secret));
    }

    protected function verifyTotp(User $user, string $code): bool
    {
        if (! preg_match('/^\d{6}$/', $code) || ! $user->two_factor_secret) {
            return false;
        }

        // window 1 = ±30 s clock drift; replay of the same code is blocked by the cache
        $key = 'totp-used:'.$user->id.':'.$code;
        if (cache()->has($key)) {
            return false;
        }
        $ok = $this->engine->verifyKey(decrypt($user->two_factor_secret), $code, 1);
        if ($ok) {
            cache()->put($key, true, 120);
        }

        return $ok;
    }
}
