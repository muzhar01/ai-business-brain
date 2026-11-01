<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Pins the security floor so a lockfile refresh or a cached base image can't silently
 * reintroduce a patched vulnerability (advisories of 2026-10-19..22).
 */
class SecurityPatchLevelTest extends TestCase
{
    private function root(string $file): string
    {
        return file_get_contents(base_path($file));
    }

    public function test_composer_constraint_floors_laravel_above_october_2026_advisories(): void
    {
        $constraint = json_decode($this->root('composer.json'), true)['require']['laravel/framework'];
        $this->assertMatchesRegularExpression('/^\^13\.(\d+)/', $constraint);
        preg_match('/^\^13\.(\d+)/', $constraint, $m);
        $this->assertGreaterThanOrEqual(30, (int) $m[1], 'GHSA-f9x3-7vq9-cph6 (13.29), GHSA-5wcm-3v2w-8362 (13.27), CVE-2026-102279 (13.30)');
    }

    public function test_locked_and_running_framework_is_patched(): void
    {
        $lock = json_decode($this->root('composer.lock'), true);
        $fw = collect($lock['packages'])->firstWhere('name', 'laravel/framework');
        $this->assertTrue(version_compare(ltrim($fw['version'], 'v'), '13.30.0', '>='), 'locked '.$fw['version']);
        $this->assertTrue(version_compare(app()->version(), '13.30.0', '>='), 'running '.app()->version());
    }

    public function test_no_guard_uses_the_legacy_token_driver(): void
    {
        foreach (config('auth.guards') as $name => $guard) {
            $this->assertNotSame('token', $guard['driver'] ?? null, "guard {$name} uses TokenGuard");
        }
    }

    public function test_dockerfile_refuses_unpatched_php_and_refreshes_ca_bundle(): void
    {
        $docker = $this->root('Dockerfile');
        $this->assertStringContainsString('FROM serversideup/php:8.4-frankenphp', $docker);
        $this->assertStringContainsString('version_compare(PHP_VERSION, "8.4.27", ">=")', $docker, 'CVE-2026-109618/109619 floor');
        $this->assertStringContainsString('--only-upgrade ca-certificates', $docker, 'eMudhra CA compromise');
        $this->assertStringContainsString('--pull', $docker, 'document pulling the floating tag');
        // The guard must run before anything else builds on the image.
        $this->assertLessThan(strpos($docker, 'install-php-extensions'), strpos($docker, 'version_compare(PHP_VERSION'));
    }

    public function test_security_doc_lists_current_patch_levels(): void
    {
        $doc = $this->root('docs/SECURITY.md');
        foreach (['8.4.27', '^13.30', 'GHSA-f9x3-7vq9-cph6', 'ca-certificates'] as $needle) {
            $this->assertStringContainsString($needle, $doc);
        }
    }
}
