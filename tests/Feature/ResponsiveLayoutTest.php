<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Dataset;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Responsive & compatibility guarantees for the app shell (cycle 2026-10-25):
 * - mobile navigation drawer (< 900px) with a real, labelled toggle button,
 * - every data table scrolls inside its own focusable region (WCAG 2.2 SC 1.4.10 Reflow),
 * - zoom is never disabled (WCAG 1.4.4), iOS dynamic viewport units with vh fallbacks,
 * - IME-safe Enter-to-send in chat (Safari / CJK input).
 */
class ResponsiveLayoutTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = ['/dashboard', '/chat', '/knowledge', '/approvals', '/agents', '/leads', '/tasks', '/datasets',
        '/imports', '/exports', '/settings', '/settings/providers', '/settings/api', '/settings/channels', '/settings/team',
        '/settings/data', '/billing', '/admin', '/profile'];

    private function css(): string
    {
        return file_get_contents(public_path('css/app.css'));
    }

    /** @return array<string, array{0: string}> */
    public static function bladeViews(): array
    {
        $out = [];
        foreach (Finder::create()->files()->in(__DIR__.'/../../resources/views')->name('*.blade.php') as $f) {
            $out[$f->getRelativePathname()] = [$f->getRealPath()];
        }

        return $out;
    }

    /** Render every app page as a super admin and return [url => html]. */
    private function renderAll(): array
    {
        [$user] = $this->tenant();
        $user->forceFill(['is_admin' => true])->save();
        $agent = Agent::first();
        $dataset = Dataset::create(['name' => 'Products', 'fields' => [['name' => 'sku', 'type' => 'string']]]);
        Tenant::forget();
        $html = [];
        foreach ([...self::PAGES, "/agents/{$agent->id}/edit", "/datasets/{$dataset->id}"] as $url) {
            $res = $this->actingAs($user)->get($url);
            $res->assertOk();
            $html[$url] = $res->getContent();
            Tenant::forget();
        }

        return $html;
    }

    private function dom(string $html): \DOMXPath
    {
        $doc = new \DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        return new \DOMXPath($doc);
    }

    // ------------------------------------------------------------ mobile navigation

    public function test_every_app_page_has_a_labelled_mobile_nav_toggle_wired_to_the_sidebar(): void
    {
        foreach ($this->renderAll() as $url => $html) {
            $x = $this->dom($html);
            $btn = $x->query('//button[@id="navtoggle"]');
            $this->assertSame(1, $btn->length, "{$url}: nav toggle missing");
            $b = $btn->item(0);
            $this->assertSame('button', $b->getAttribute('type'), $url);
            $this->assertSame('sidenav', $b->getAttribute('aria-controls'), $url);
            $this->assertSame('false', $b->getAttribute('aria-expanded'), $url);
            $this->assertNotSame('', $b->getAttribute('aria-label'), $url);
            $this->assertSame(1, $x->query('//aside[@id="sidenav"][@aria-label]')->length, "{$url}: sidebar landmark");
            $this->assertSame(1, $x->query('//div[@id="navscrim"][@hidden]')->length, "{$url}: scrim hidden by default");
            $this->assertSame(1, $x->query('//main[@id="main"]')->length, $url);
            // Skip link stays the first focusable thing on the page.
            $first = $x->query('//body//*[self::a or self::button or self::input or self::select or self::textarea][1]')->item(0);
            $this->assertSame('skip', $first->getAttribute('class'), "{$url}: skip link must come first");
        }
    }

    public function test_layout_marks_js_capable_browsers_so_the_drawer_degrades_without_js(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $this->assertStringContainsString("document.documentElement.classList.add('js')", $layout);
        // The drawer only hides the sidebar when JS is running; otherwise it stays a static, stacked nav.
        $this->assertMatchesRegularExpression('/\.js \.side\{position:fixed/', $this->css());
        $this->assertStringContainsString("e.key === 'Escape'", $layout, 'Escape closes the drawer');
        $this->assertStringContainsString('btn.focus()', $layout, 'focus returns to the toggle on close');
        $this->assertStringContainsString("setAttribute('aria-expanded'", $layout);
    }

    public function test_drawer_css_is_hidden_off_canvas_rtl_aware_and_toggle_meets_target_size(): void
    {
        $css = $this->css();
        $this->assertMatchesRegularExpression('/\.js \.side\{[^}]*transform:translateX\(-100%\)[^}]*visibility:hidden/', $css, 'closed drawer leaves the tab order');
        $this->assertStringContainsString('.js body.nav-open .side{transform:none;visibility:visible}', $css);
        $this->assertStringContainsString('inset-inline-start:0', $css, 'logical property so RTL docks to the right');
        $this->assertStringContainsString('.js[dir=rtl] .side{transform:translateX(100%)}', str_replace('[dir=rtl] .js .side,', '', $css));
        $this->assertMatchesRegularExpression('/\.navtoggle\{[^}]*min-width:44px;min-height:44px/', $css, 'WCAG 2.5.8 / Apple HIG 44px target');
        $this->assertStringContainsString('@media(min-width:901px){.navtoggle{display:none}}', $css);
        $this->assertStringContainsString('.topbar,.scrim{display:none}', $css, 'no mobile chrome on desktop');
        $this->assertStringContainsString('env(safe-area-inset-top)', $css, 'notch-safe top bar');
        $this->assertStringContainsString('@media(prefers-reduced-motion:reduce){*{transition:none!important', $css, 'drawer slide respects reduced motion');
    }

    // ------------------------------------------------------------ reflow / tables

    #[DataProvider('bladeViews')]
    public function test_every_table_in_every_view_sits_in_a_labelled_scroll_region(string $path): void
    {
        $src = file_get_contents($path);
        $tables = substr_count($src, '<table');
        $wraps = preg_match_all('/<div class="tablewrap" role="region" tabindex="0" aria-label="[^"]+"><table/', $src);
        $this->assertSame($tables, $wraps, basename($path).': wrap each <table> in .tablewrap (role=region, tabindex=0, aria-label)');
        $this->assertSame(substr_count($src, '</table>'), substr_count($src, '</table></div>'));
    }

    public function test_rendered_tables_are_wrapped_and_regions_have_unique_labels_per_page(): void
    {
        $seen = 0;
        foreach ($this->renderAll() as $url => $html) {
            $x = $this->dom($html);
            foreach ($x->query('//table') as $t) {
                $seen++;
                $p = $t->parentNode;
                $this->assertSame('tablewrap', $p->getAttribute('class'), "{$url}: table not wrapped");
                $this->assertSame('region', $p->getAttribute('role'), $url);
                $this->assertSame('0', $p->getAttribute('tabindex'), "{$url}: keyboard users must be able to scroll it");
            }
            $labels = array_map(fn ($n) => $n->getAttribute('aria-label'), iterator_to_array($x->query('//div[@class="tablewrap"]')));
            $this->assertSame(array_unique($labels), $labels, "{$url}: duplicate region labels");
        }
        $this->assertGreaterThan(10, $seen, 'sanity: pages rendered tables');
    }

    public function test_table_regions_scroll_horizontally_instead_of_the_page(): void
    {
        $css = $this->css();
        $this->assertStringContainsString('.tablewrap{overflow-x:auto', $css);
        $this->assertStringContainsString('.main{min-width:0}', $css, 'grid child may shrink below its content');
        $this->assertStringContainsString('td{overflow-wrap:anywhere}', $css, 'long tokens/URLs wrap (WCAG C33)');
    }

    // ------------------------------------------------------------ viewport & browser compatibility

    #[DataProvider('bladeViews')]
    public function test_no_view_disables_pinch_zoom(string $path): void
    {
        $src = file_get_contents($path);
        $this->assertDoesNotMatchRegularExpression('/user-scalable\s*=\s*(no|0)/i', $src);
        $this->assertDoesNotMatchRegularExpression('/maximum-scale\s*=\s*(1(\.0)?|[0-4](\.\d+)?)\b/i', $src);
        if (preg_match('/<head[\s>]/', $src)) {
            $this->assertStringContainsString('name="viewport" content="width=device-width, initial-scale=1"', $src, basename($path));
        }
    }

    public function test_full_height_regions_use_dvh_with_vh_fallback_for_old_safari(): void
    {
        $css = $this->css();
        foreach (['height:100vh;height:100dvh', 'height:calc(100vh - 120px);height:calc(100dvh - 120px)'] as $pair) {
            $this->assertStringContainsString($pair, $css, "vh fallback must precede dvh: {$pair}");
        }
        $this->assertDoesNotMatchRegularExpression('/(?<!100vh;)height:100dvh/', str_replace('height:100vh;height:100dvh', '', $css), 'every dvh has a vh fallback');
    }

    public function test_chat_enter_to_send_is_ime_safe_and_mobile_friendly(): void
    {
        $chat = file_get_contents(resource_path('views/app/chat.blade.php'));
        $this->assertStringContainsString('!event.isComposing', $chat, 'Enter that confirms an IME candidate must not send');
        $this->assertStringContainsString('event.keyCode!==229', $chat, 'Safari reports IME Enter as keyCode 229');
        $this->assertStringContainsString('enterkeyhint="send"', $chat);
        $this->assertStringContainsString("aria-label=\"{{ __('Message') }}\"", $chat);
        $this->assertStringContainsString('.chatwrap{height:auto}', $this->css(), 'chat stacks naturally on phones');
    }

    public function test_css_braces_are_balanced(): void
    {
        $css = $this->css();
        $this->assertSame(substr_count($css, '{'), substr_count($css, '}'));
    }
}
