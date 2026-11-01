<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlashLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_controllers_never_flash_untranslated_literals(): void
    {
        $bad = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Http'))) as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }
            $src = file_get_contents($f->getPathname());
            if (preg_match("/with\\('status', ['\"]|withErrors\\(\\['\\w+' => ['\"]/", $src)) {
                $bad[] = $f->getFilename();
            }
        }
        $this->assertSame([], $bad, 'wrap flash/error messages in __()');
    }

    public function test_flash_messages_follow_the_request_language(): void
    {
        foreach (['ur', 'ar'] as $loc) {
            $res = $this->withHeader('Accept-Language', $loc)->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password-1']);
            $res->assertSessionHasErrors('email');
            $msg = session('errors')->first('email');
            $this->assertSame(__('These credentials do not match our records.', [], $loc), $msg);
            $this->assertNotSame('These credentials do not match our records.', $msg);
        }
    }

    public function test_placeholder_messages_keep_values_in_every_language(): void
    {
        foreach (['en', 'ur', 'ar'] as $loc) {
            $this->assertStringContainsString('Acme', __('Workspace :name created.', ['name' => 'Acme'], $loc));
        }
    }
}
