<?php

namespace Tests\Unit;

use App\Security\Sealed;
use App\Webhooks\Events;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Field encryption and webhook signatures over seeded random inputs. */
class CryptoMatrixTest extends TestCase
{
    public static function payloads(): iterable
    {
        mt_srand(7007);
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 .,;:!?\'"<>/\\{}[]()@#$%^&*-_=+~`|اردوعربی中文😀ñü';
        $chars = preg_split('//u', $alphabet, -1, PREG_SPLIT_NO_EMPTY);
        for ($i = 0; $i < 200; $i++) {
            $len = mt_rand(1, 400);
            $s = '';
            for ($j = 0; $j < $len; $j++) {
                $s .= $chars[mt_rand(0, count($chars) - 1)];
            }
            yield "random {$i}" => [$s];
        }
        yield 'json' => ['{"a":1}'];
        yield 'looks prefixed' => ['enc-not-really'];
        yield 'long' => [str_repeat('PHI ', 20000)];
    }

    #[DataProvider('payloads')]
    public function test_sealed_round_trip(string $plain): void
    {
        $cast = new Sealed;
        $stored = $cast->set(null, 'content', $plain, []);
        $this->assertStringStartsWith(Sealed::PREFIX, $stored);
        $this->assertStringNotContainsString($plain, $stored);
        $this->assertSame($plain, $cast->get(null, 'content', $stored, []));
        $this->assertSame($stored, $cast->set(null, 'content', $stored, []), 'never double-encrypts');
    }

    #[DataProvider('payloads')]
    public function test_webhook_signature_round_trip_and_tamper(string $body): void
    {
        $secret = 'whsec_'.Str::random(40);
        $t = time();
        $sig = Events::sign($secret, $body, $t);
        $this->assertTrue(Events::verify($secret, $body, $sig));
        $this->assertFalse(Events::verify($secret, $body.'x', $sig), 'tampered body');
        $this->assertFalse(Events::verify($secret.'x', $body, $sig), 'wrong secret');
        $this->assertFalse(Events::verify($secret, $body, Events::sign($secret, $body, $t - 3600)), 'replayed old timestamp');
    }

    public function test_legacy_plaintext_is_read_transparently(): void
    {
        $this->assertSame('old plain row', (new Sealed)->get(null, 'content', 'old plain row', []));
        $this->assertNull((new Sealed)->set(null, 'content', null, []));
    }

    public function test_disabled_encryption_stores_plaintext(): void
    {
        config(['brain.security.encrypt_at_rest' => false]);
        $this->assertSame('plain', (new Sealed)->set(null, 'content', 'plain', []));
    }
}
