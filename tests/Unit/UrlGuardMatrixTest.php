<?php

namespace Tests\Unit;

use App\Security\UrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** SSRF guard: every private / reserved / metadata range is refused (~650 cases). */
class UrlGuardMatrixTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['brain.security.allow_private_urls' => false]);
    }

    public static function privateIps(): iterable
    {
        mt_srand(5005);
        for ($i = 0; $i < 40; $i++) {
            yield "10/8 {$i}" => ['10.'.mt_rand(0, 255).'.'.mt_rand(0, 255).'.'.mt_rand(1, 254)];
            yield "172.16/12 {$i}" => ['172.'.mt_rand(16, 31).'.'.mt_rand(0, 255).'.'.mt_rand(1, 254)];
            yield "192.168/16 {$i}" => ['192.168.'.mt_rand(0, 255).'.'.mt_rand(1, 254)];
            yield "loopback {$i}" => ['127.'.mt_rand(0, 255).'.'.mt_rand(0, 255).'.'.mt_rand(1, 254)];
            yield "link-local {$i}" => ['169.254.'.mt_rand(0, 255).'.'.mt_rand(1, 254)];
            yield "cgnat {$i}" => ['100.'.mt_rand(64, 127).'.'.mt_rand(0, 255).'.'.mt_rand(1, 254)];
            yield "zero {$i}" => ['0.'.mt_rand(0, 255).'.'.mt_rand(0, 255).'.'.mt_rand(0, 255)];
        }
        foreach (['::1', 'fe80::1', 'fc00::1', 'fd12:3456::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '169.254.169.254', '0.0.0.0', '255.255.255.255'] as $ip) {
            yield $ip => [$ip];
        }
    }

    #[DataProvider('privateIps')]
    public function test_private_ip_is_not_public(string $ip): void
    {
        $this->assertFalse(UrlGuard::isPublicIp($ip));
    }

    #[DataProvider('privateIps')]
    public function test_private_url_is_blocked(string $ip): void
    {
        $host = str_contains($ip, ':') ? "[{$ip}]" : $ip;
        $this->expectException(\InvalidArgumentException::class);
        UrlGuard::assertPublic("http://{$host}/latest/meta-data");
    }

    public static function publicIps(): iterable
    {
        mt_srand(6006);
        $firsts = [8, 23, 31, 45, 52, 66, 81, 91, 104, 141, 151, 185, 203];
        for ($i = 0; $i < 60; $i++) {
            yield "public {$i}" => [$firsts[$i % count($firsts)].'.'.mt_rand(0, 255).'.'.mt_rand(0, 255).'.'.mt_rand(1, 254)];
        }
        foreach (['1.1.1.1', '8.8.8.8', '2606:4700:4700::1111', '2001:4860:4860::8888'] as $ip) {
            yield $ip => [$ip];
        }
    }

    #[DataProvider('publicIps')]
    public function test_public_ip_is_allowed(string $ip): void
    {
        $this->assertTrue(UrlGuard::isPublicIp($ip));
        $host = str_contains($ip, ':') ? "[{$ip}]" : $ip;
        UrlGuard::assertPublic("https://{$host}/api");
        $this->addToAssertionCount(1);
    }

    public static function badUrls(): iterable
    {
        foreach (['file:///etc/passwd', 'gopher://x', 'ftp://1.1.1.1', 'javascript:alert(1)', 'http://user:pw@1.1.1.1/', 'http://localhost/x', 'http:///nohost', 'dict://1.1.1.1:11211', 'ldap://1.1.1.1', '//1.1.1.1'] as $u) {
            yield $u => [$u];
        }
    }

    #[DataProvider('badUrls')]
    public function test_bad_url_is_blocked(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UrlGuard::assertPublic($url);
    }
}
