<?php

namespace Tests\Feature;

use Composer\Autoload\ClassLoader;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Every third-party class the app imports must come from a package that composer.lock installs
 * in production (no-dev). Guards against "works on my vendor/" drift: a package installed by hand
 * but missing from composer.json breaks a fresh `composer install` (found 2026-10-25 for
 * laravel/pulse and laravel/socialite).
 */
class DependencyManifestTest extends TestCase
{
    public function test_imported_vendor_classes_come_from_locked_production_packages(): void
    {
        $root = base_path();
        $lock = json_decode(file_get_contents("{$root}/composer.lock"), true);
        $prod = array_flip(array_column($lock['packages'], 'name'));
        $checked = 0;

        $files = Finder::create()->files()->name('*.php')->in(["{$root}/app", "{$root}/config", "{$root}/routes", "{$root}/bootstrap"])->exclude('cache');
        foreach ($files as $file) {
            preg_match_all('/^use\s+([A-Z][\w\\\\]+)(?:\s+as\s+\w+)?;/m', $file->getContents(), $m);
            foreach ($m[1] as $class) {
                if (str_starts_with($class, 'App\\') || str_starts_with($class, 'Tests\\')) {
                    continue;
                }
                if (! class_exists($class) && ! interface_exists($class) && ! trait_exists($class) && ! enum_exists($class)) {
                    if (function_exists($class) || $this->isNamespace($class)) {
                        continue; // `use Foo\Bar;` may import a namespace (e.g. Laravel\Pulse\Recorders)
                    }
                    $this->fail("{$file->getRelativePathname()}: {$class} is not autoloadable");
                }
                $path = (new \ReflectionClass($class))->getFileName();
                if ($path === false || ! str_contains($path, '/vendor/')) {
                    continue; // PHP core / extension
                }
                $rel = explode('/', substr($path, strpos($path, '/vendor/') + 8));
                $package = $rel[0].'/'.$rel[1];
                $this->assertArrayHasKey($package, $prod, "{$file->getRelativePathname()} uses {$class} from {$package}, which is not a locked production dependency");
                $checked++;
            }
        }
        $this->assertGreaterThan(50, $checked);
    }

    private function isNamespace(string $ns): bool
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            foreach ($loader->getPrefixesPsr4() as $prefix => $dirs) {
                if (str_starts_with($ns.'\\', $prefix)) {
                    foreach ($dirs as $dir) {
                        if (is_dir($dir.'/'.str_replace('\\', '/', substr($ns.'\\', strlen($prefix))))) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    public function test_features_from_earlier_releases_are_declared_in_composer_json(): void
    {
        $require = json_decode(file_get_contents(base_path('composer.json')), true)['require'];
        foreach (['laravel/pulse', 'laravel/socialite', 'laravel/octane', 'laravel/horizon', 'laravel/cashier', 'pragmarx/google2fa', 'spatie/laravel-backup'] as $pkg) {
            $this->assertArrayHasKey($pkg, $require, $pkg);
        }
    }
}
