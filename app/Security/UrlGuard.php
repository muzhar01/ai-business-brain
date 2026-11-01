<?php

namespace App\Security;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * SSRF protection for every URL the platform fetches on a tenant's behalf
 * (web-page ingestion, URL imports, outbound webhooks). Blocks loopback,
 * private, link-local (cloud metadata 169.254.169.254) and reserved ranges.
 */
class UrlGuard
{
    public static function assertPublic(string $url): void
    {
        if (config('brain.security.allow_private_urls')) {
            return;
        }

        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new InvalidArgumentException('Only http(s) URLs are allowed.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('URLs with credentials are not allowed.');
        }

        $host = trim($host, '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);

        if (! $ips) {
            throw new InvalidArgumentException("Cannot resolve host {$host}.");
        }
        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                throw new InvalidArgumentException('URLs pointing to private or internal networks are not allowed.');
            }
        }
    }

    /** An HTTP client that validates the URL and every redirect hop. */
    public static function http(?string $url = null): PendingRequest
    {
        if ($url !== null) {
            self::assertPublic($url);
        }

        return Http::withOptions(['allow_redirects' => [
            'max' => 3,
            'protocols' => ['http', 'https'],
            'on_redirect' => fn ($request, $response, $uri) => self::assertPublic((string) $uri),
        ]]);
    }

    public static function isPublicIp(string $ip): bool
    {
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            $ip = substr($ip, 7);
        }

        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            && ! preg_match('/^(0\.|100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.|169\.254\.|fe80:|fc|fd)/i', $ip);
    }

    protected static function resolve(string $host): array
    {
        if (strtolower($host) === 'localhost') {
            return ['127.0.0.1'];
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $ips = array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records));

        return $ips ?: array_filter([gethostbyname($host) !== $host ? gethostbyname($host) : null]);
    }
}
