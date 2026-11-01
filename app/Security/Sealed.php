<?php

namespace App\Security;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Field-level encryption at rest (AES-256 + HMAC via APP_KEY) for PHI/PII columns.
 * Reads legacy plaintext transparently, so existing installs migrate without downtime
 * (run `php artisan brain:encrypt-existing` to seal old rows).
 */
class Sealed implements CastsAttributes
{
    public const PREFIX = 'enc:';

    public function get($model, string $key, mixed $value, array $attributes): mixed
    {
        if (! is_string($value) || ! str_starts_with($value, self::PREFIX)) {
            return $value;
        }

        try {
            return Crypt::decryptString(substr($value, strlen(self::PREFIX)));
        } catch (DecryptException) {
            return '[encrypted: key mismatch]';
        }
    }

    public function set($model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '' || ! config('brain.security.encrypt_at_rest', true) || str_starts_with((string) $value, self::PREFIX)) {
            return $value;
        }

        return self::PREFIX.Crypt::encryptString((string) $value);
    }
}
