<?php

namespace Tests\Feature;

use App\Support\Direction;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Right-to-left & bidi support (cycle 2026-10-26; RTL Styling 101, Crisp Sept 2026 "better RTL message support"):
 * - <html lang dir> follows the locale on every layout, so flex/grid/tables flip by themselves,
 * - app.css uses logical properties only (no physical left/right), with RTL fixes for drawer, letter-spacing, code,
 * - user-written text (chat bubbles, titles, inputs, widget messages) uses dir="auto",
 * - bubbles are aligned by flex (container direction), never by the bubble's own direction,
 * - directional arrows are wrapped so they mirror.
 */
class RtlSupportTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> */
    public static function locales(): array
    {
        return [
            'ar' => ['ar', 'rtl'], 'ar-SA' => ['ar-SA', 'rtl'], 'ur' => ['ur', 'rtl'], 'fa_IR' => ['fa_IR', 'rtl'],
            'he' => ['he', 'rtl'], 'yi' => ['yi', 'rtl'], 'AR upper' => ['AR', 'rtl'],
            'en' => ['en', 'ltr'], 'en_US' => ['en_US', 'ltr'], 'fr' => ['fr', 'ltr'], 'de' => ['de', 'ltr'],
            'zh-CN' => ['zh-CN', 'ltr'], 'empty' => ['', 'ltr'],
        ];
    }

    #[DataProvider('locales')]
    public function test_direction_for_locale(string $locale, string $dir): void
    {
        $this->assertSame($dir, Direction::of($locale));
    }

    public function test_lang_tag_uses_hyphen(): void
    {
        $this->assertSame('en-US', Direction::lang('en_US'));
        $this->assertSame('ar', Direction::lang('ar'));
    }

    public function test_every_html_root_uses_the_direction_helper(): void
    {
        $roots = 0;
        foreach (Finder::create()->files()->in(__DIR__.'/../../resources/views')->name('*.blade.php') as $f) {
            $src = $f->getContents();
            if (str_contains($src, '<html')) {
                $roots++;
                $this->assertStringContainsString('dir="{{ \App\Support\Direction::of() }}"', $src, $f->getRelativePathname());
                $this->assertStringContainsString('lang="{{ \App\Support\Direction::lang() }}"', $src, $f->getRelativePathname());
            }
        }
        $this->assertGreaterThanOrEqual(4, $roots);
    }

    public function test_pages_render_rtl_for_arabic_and_ltr_for_english(): void
    {
        // Since v9 the locale is resolved per request (SetLocale, ADR 0001), so ask for it like a browser does.
        foreach (['/login', '/does-not-exist'] as $url) {
            foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $dir) {
                $this->assertMatchesRegularExpression('/<html lang="'.$locale.'" dir="'.$dir.'"/', $this->get($url, ['Accept-Language' => $locale])->getContent(), "$url [$locale]");
            }
        }
        [$user] = $this->tenant();
        Tenant::forget();
        foreach (['/dashboard', '/chat', '/leads'] as $url) {
            foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $dir) {
                $res = $this->actingAs($user)->get($url, ['Accept-Language' => $locale]);
                $this->assertMatchesRegularExpression('/<html lang="'.$locale.'" dir="'.$dir.'"/', $res->getContent(), "$url [$locale]");
                Tenant::forget();
            }
        }
    }

    public function test_css_has_no_physical_directional_properties(): void
    {
        $css = file_get_contents(public_path('css/app.css'));
        $bad = [
            '/(margin|padding)-(left|right)\s*:/', '/text-align\s*:\s*(left|right)/', '/float\s*:\s*(left|right)/',
            '/(^|[;{\s])(left|right)\s*:/', '/border-(left|right)(-[a-z]+)?\s*:/', '/border-(top|bottom)-(left|right)-radius/',
        ];
        foreach ($bad as $re) {
            $this->assertSame(0, preg_match($re, $css, $m), "physical property in app.css: {$re} → ".($m[0] ?? ''));
        }
    }

    public function test_css_rtl_rules(): void
    {
        $css = file_get_contents(public_path('css/app.css'));
        $this->assertStringContainsString('th,td{text-align:start', $css);
        $this->assertStringContainsString('.right{margin-inline-start:auto}', $css);
        $this->assertStringContainsString('inset-inline-start:-9999px', $css, 'skip link stays off-screen in RTL');
        $this->assertStringContainsString('[dir=rtl] .js .side,.js[dir=rtl] .side{transform:translateX(100%)}', $css, 'drawer opens from the right');
        $this->assertStringContainsString('[dir=rtl] .dir-arrow{transform:scaleX(-1)}', $css);
        $this->assertMatchesRegularExpression('/\[dir=rtl\] \.sec,\[dir=rtl\] th,\[dir=rtl\] \.stat \.l\{letter-spacing:0\}/', $css, 'Arabic letters must stay joined');
        $this->assertMatchesRegularExpression('/\[dir=rtl\] input\[type=email\].*\[dir=rtl\] code.*\{direction:ltr\}/', $css, 'email/url/tel/code stay LTR');
    }

    public function test_chat_bubbles_align_by_container_not_by_own_direction(): void
    {
        $css = file_get_contents(public_path('css/app.css'));
        $this->assertMatchesRegularExpression('/\.log\{[^}]*display:flex;flex-direction:column\}/', $css);
        $this->assertMatchesRegularExpression('/\.msg\.user\{[^}]*align-self:flex-end/', $css);
        $this->assertDoesNotMatchRegularExpression('/\.msg\.user\{[^}]*margin-inline-start:auto/', $css, 'a dir=auto bubble would flip sides');
    }

    public function test_user_written_text_uses_dir_auto_in_chat(): void
    {
        [$user] = $this->tenant();
        $html = $this->actingAs($user)->get('/chat')->getContent();
        $this->assertStringContainsString("d.dir = 'auto'", $html, 'bubbles');
        $this->assertStringContainsString('<textarea id="q" dir="auto"', $html);
        $this->assertStringContainsString('<input id="search" dir="auto"', $html);
        $this->assertStringContainsString('<b id="ctitle" dir="auto">', $html);
        $this->assertStringContainsString('<div dir="auto" class="conv', $html);
    }

    public function test_widget_script_is_rtl_aware(): void
    {
        $js = $this->get('/widget.js')->getContent();
        $this->assertStringContainsString("getComputedStyle(document.documentElement).direction === 'rtl'", $js, 'follows the host page');
        $this->assertStringContainsString('#bw[dir=rtl]{right:auto;left:20px}', $js);
        $this->assertStringContainsString('#bw-btn[dir=rtl]{right:auto;left:20px}', $js);
        $this->assertStringContainsString("d.dir = 'auto'", $js, 'messages');
        $this->assertStringContainsString("input.dir = 'auto'", $js);
        $this->assertStringContainsString('align-self:flex-end', $js);
        $this->assertStringNotContainsString('margin-left:auto', $js);
    }

    public function test_directional_arrows_mirror_in_every_app_view(): void
    {
        $wrapped = 0;
        foreach (Finder::create()->files()->in(__DIR__.'/../../resources/views')->name('*.blade.php')->notName('welcome.blade.php') as $f) {
            $src = $f->getContents();
            $stripped = str_replace('<span class="dir-arrow" aria-hidden="true">→</span>', '', $src, $n);
            $wrapped += $n;
            $this->assertStringNotContainsString('→', $stripped, $f->getRelativePathname().' has an unwrapped arrow');
            $this->assertStringNotContainsString('←', $stripped, $f->getRelativePathname());
        }
        $this->assertGreaterThanOrEqual(9, $wrapped);
    }

    public function test_no_inline_physical_direction_styles_in_app_views(): void
    {
        foreach (Finder::create()->files()->in(__DIR__.'/../../resources/views')->name('*.blade.php')->notName('welcome.blade.php')->notPath('widget') as $f) {
            $this->assertDoesNotMatchRegularExpression('/style="[^"]*(margin|padding)-(left|right)|style="[^"]*text-align:\s*(left|right)/', $f->getContents(), $f->getRelativePathname());
        }
    }
}
