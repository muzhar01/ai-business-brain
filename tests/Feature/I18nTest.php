<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceSecurityPolicy;
use App\Http\Middleware\SetCurrentWorkspace;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Locale;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * i18n slice 1 (cycle v9, docs/adr/0001-i18n.md): locale resolution, the switcher, the workspace default,
 * the JSON catalogues (en, ur, ar) and the translated chrome (layouts, nav, auth, errors, widget).
 */
class I18nTest extends TestCase
{
    use RefreshDatabase;

    /** Views whose every visible string must go through __() in slice 1. */
    /** Keys looked up via __($variable): status, priority and filter values. */
    private const DYNAMIC_KEYS = ['open', 'done', 'all', 'normal', 'low', 'high', 'urgent', 'pending', 'approved', 'rejected', 'executed', 'failed', 'new', 'contacted', 'qualified', 'proposal', 'won', 'lost', 'processing', 'ready', 'queued', 'draft', 'upload', 'url', 'database', 'hourly', 'daily', 'weekly', 'running', 'delivered'];

    private const SCOPED_VIEWS = [
        'layouts/app.blade.php', 'layouts/guest.blade.php', 'partials/locale-switcher.blade.php',
        'auth/login.blade.php', 'auth/register.blade.php', 'auth/forgot.blade.php', 'auth/reset.blade.php',
        'auth/two-factor.blade.php', 'auth/workspace.blade.php',
        'app/dashboard.blade.php', 'app/approvals.blade.php', 'app/tasks.blade.php', 'app/leads.blade.php', 'app/knowledge.blade.php', 'app/chat.blade.php', 'app/agents/index.blade.php', 'app/agents/form.blade.php',
        'app/datasets/index.blade.php', 'app/datasets/show.blade.php', 'app/imports/create.blade.php', 'app/imports/index.blade.php', 'app/imports/map.blade.php', 'app/imports/show.blade.php', 'app/exports.blade.php',
        'app/settings/general.blade.php', 'app/settings/team.blade.php', 'app/settings/data.blade.php', 'app/settings/api.blade.php', 'app/settings/channels.blade.php', 'app/settings/privacy.blade.php', 'app/settings/providers.blade.php', 'app/settings/webhooks.blade.php', 'partials/settings-tabs.blade.php', 'app/billing.blade.php', 'app/profile/show.blade.php', 'app/profile/security.blade.php', 'app/admin.blade.php',
        'errors/layout.blade.php', 'errors/401.blade.php', 'errors/402.blade.php', 'errors/403.blade.php', 'errors/404.blade.php',
        'errors/419.blade.php', 'errors/429.blade.php', 'errors/500.blade.php', 'errors/503.blade.php',
    ];

    /** @return list<string> keys built at runtime from a prefix (quota.*, feature.*, event.*) plus plan names */
    private static function dynamicPrefixed(array $en): array
    {
        $keys = array_values(array_filter(array_keys($en), fn ($k) => preg_match('/^(quota|feature|event)\./', $k) === 1));

        return array_merge($keys, ['Free', 'Business', 'Enterprise']);
    }

    /** @return array<string, string> */
    private function catalogue(string $locale): array
    {
        $json = json_decode(file_get_contents(lang_path($locale.'.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($json);

        return $json;
    }

    /** @return list<string> */
    private function placeholders(string $text): array
    {
        preg_match_all('/:[a-zA-Z_]+/', $text, $m);
        $p = $m[0];
        sort($p);

        return $p;
    }

    private function userIn(?string $wsLanguage = null, ?string $userLocale = null): array
    {
        [$user, $ws] = $this->tenant();
        if ($wsLanguage) {
            $ws->updateSettings(['language' => $wsLanguage]);
        }
        if ($userLocale) {
            $user->forceFill(['locale' => $userLocale])->save();
        }
        Tenant::forget();

        return [$user->fresh(), $ws->fresh()];
    }

    /* ---------- Locale helper ---------- */

    /** @return array<string, array{0: mixed, 1: ?string}> */
    public static function tags(): array
    {
        return [
            'en' => ['en', 'en'], 'ur' => ['ur', 'ur'], 'ar' => ['ar', 'ar'], 'ur-PK' => ['ur-PK', 'ur'], 'ar_SA' => ['ar_SA', 'ar'],
            'upper' => ['AR', 'ar'], 'en-GB' => ['en-GB', 'en'], 'unsupported fr' => ['fr', null], 'empty' => ['', null], 'null' => [null, null],
            'traversal' => ['../../etc/passwd', null], 'traversal en' => ['en/../../x', null], 'backslash' => ['ar\\..\\x', null],
            'array' => [['ar'], null], 'html' => ['<script>', null], 'too long' => [str_repeat('a', 40), null],
        ];
    }

    #[DataProvider('tags')]
    public function test_match_normalises_and_allow_lists(mixed $tag, ?string $expected): void
    {
        $this->assertSame($expected, Locale::match($tag));
    }

    public function test_supported_list_starts_with_en_ur_ar_in_their_own_names(): void
    {
        $this->assertSame(['en' => 'English', 'ur' => 'اردو', 'ar' => 'العربية'], Locale::supported());
    }

    public function test_resolution_order(): void
    {
        $request = Request::create('/', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => 'ar-SA,ar;q=0.9']);
        $ws = new Workspace(['settings' => ['language' => 'ur']]);
        $user = new User;

        $user->locale = 'en';
        $this->assertSame('en', Locale::resolve($user, 'ar', $ws, $request), 'user preference wins');
        $user->locale = null;
        $this->assertSame('ar', Locale::resolve($user, 'ar', $ws, $request), 'then the session choice');
        $this->assertSame('ur', Locale::resolve($user, null, $ws, $request), 'then the workspace default');
        $this->assertSame('ar', Locale::resolve($user, null, null, $request), 'then Accept-Language');
        $this->assertSame('en', Locale::resolve(null, null, null, Request::create('/', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr,de;q=0.8'])), 'then config');
        $user->locale = 'xx';
        $this->assertSame('ur', Locale::resolve($user, '../x', $ws, $request), 'invalid stored values are skipped');
    }

    public function test_accept_language_honours_quality_values(): void
    {
        $r = Request::create('/', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr;q=1, ar;q=0.5, ur-PK;q=0.8']);
        $this->assertSame('ur', Locale::fromAcceptLanguage($r));
    }

    /* ---------- Middleware ---------- */

    public function test_user_preference_sets_locale_and_direction(): void
    {
        [$user] = $this->userIn('ur', 'ar');
        $res = $this->actingAs($user)->get('/dashboard')->assertOk();
        $res->assertSee('<html lang="ar" dir="rtl">', false)->assertSee('لوحة التحكم')->assertSee('تسجيل الخروج');
        $this->assertStringContainsString('Accept-Language', (string) $res->headers->get('Vary'));
    }

    public function test_workspace_default_applies_when_user_has_none(): void
    {
        [$user] = $this->userIn('ur');
        $this->actingAs($user)->get('/dashboard')->assertOk()
            ->assertSee('<html lang="ur" dir="rtl">', false)->assertSee('ڈیش بورڈ')->assertSee('سائن آؤٹ');
    }

    public function test_accept_language_then_config_fallback(): void
    {
        [$user] = $this->userIn();
        $this->actingAs($user)->get('/dashboard', ['Accept-Language' => 'ar-EG,ar;q=0.9,en;q=0.5'])->assertSee('<html lang="ar" dir="rtl">', false);
        Tenant::forget();
        $this->actingAs($user)->get('/dashboard', ['Accept-Language' => 'de-DE'])->assertSee('<html lang="en" dir="ltr">', false)->assertSee('Dashboard');
    }

    public function test_session_choice_beats_workspace_default_but_not_user_preference(): void
    {
        [$user] = $this->userIn('ur');
        $this->actingAs($user)->withSession(['locale' => 'ar'])->get('/dashboard')->assertSee('dir="rtl"', false)->assertSee('لوحة التحكم');
        Tenant::forget();
        $user->forceFill(['locale' => 'en'])->save();
        $this->actingAs($user)->withSession(['locale' => 'ar'])->get('/dashboard')->assertSee('<html lang="en" dir="ltr">', false);
    }

    public function test_set_locale_runs_after_the_tenant_is_known(): void
    {
        $stack = Route::getRoutes()->getByName('dashboard')->gatherMiddleware();
        $sorted = app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName('dashboard'));
        $this->assertContains('web', $stack);
        $this->assertGreaterThan(array_search(SetCurrentWorkspace::class, $sorted, true), array_search(SetLocale::class, $sorted, true));
        $this->assertLessThan(array_search(EnforceSecurityPolicy::class, $sorted, true), array_search(SetLocale::class, $sorted, true));
    }

    /* ---------- Guest pages ---------- */

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function guestPages(): array
    {
        return [
            'login ur' => ['/login', 'ur', 'سائن اِن کریں'], 'login ar' => ['/login', 'ar', 'تذكرني'],
            'register ur' => ['/register', 'ur', 'اکاؤنٹ بنائیں'], 'register ar' => ['/register', 'ar', 'تأكيد كلمة المرور'],
            'forgot ur' => ['/forgot-password', 'ur', 'ری سیٹ لنک بھیجیں'], 'forgot ar' => ['/forgot-password', 'ar', 'إرسال رابط إعادة التعيين'],
            'reset ur' => ['/reset-password/tok', 'ur', 'نیا پاس ورڈ منتخب کریں'], 'reset ar' => ['/reset-password/tok', 'ar', 'تحديث كلمة المرور'],
        ];
    }

    #[DataProvider('guestPages')]
    public function test_guest_pages_render_rtl_and_translated(string $url, string $locale, string $text): void
    {
        $this->get($url, ['Accept-Language' => $locale])->assertOk()
            ->assertSee('<html lang="'.$locale.'" dir="rtl">', false)->assertSee($text)
            ->assertSee('name="locale"', false);
    }

    public function test_two_factor_challenge_is_translated(): void
    {
        $user = User::factory()->create();
        $this->withSession(['locale' => 'ar', '2fa:user' => $user->id, 'two_factor_user' => $user->id])->get('/two-factor')
            ->assertSee('dir="rtl"', false);
        $this->assertSame('التحقق بخطوتين', __('Two-factor check', [], 'ar'));
        $this->assertStringContainsString("__('Two-factor check')", file_get_contents(resource_path('views/auth/two-factor.blade.php')));
    }

    public function test_login_page_in_english_by_default(): void
    {
        $this->get('/login')->assertOk()->assertSee('<html lang="en" dir="ltr">', false)->assertSee('Remember me');
    }

    /* ---------- Error pages ---------- */

    public function test_unmatched_route_404_uses_browser_language(): void
    {
        $this->get('/definitely-not-a-page', ['Accept-Language' => 'ar'])->assertNotFound()
            ->assertSee('dir="rtl"', false)->assertSee('الصفحة غير موجودة')->assertSee('الذهاب إلى الصفحة الرئيسية');
        $this->get('/definitely-not-a-page', ['Accept-Language' => 'ur'])->assertNotFound()->assertSee('صفحہ نہیں ملا');
    }

    public function test_error_views_render_translated_for_every_code(): void
    {
        foreach (['401', '402', '403', '404', '419', '429', '500', '503'] as $code) {
            app()->setLocale('ur');
            $html = view('errors.'.$code)->render();
            $this->assertStringContainsString('dir="rtl"', $html, $code);
            $this->assertDoesNotMatchRegularExpression('/<h1>[A-Za-z ]+<\/h1>/', $html, $code.' title not translated');
        }
    }

    /* ---------- Switcher ---------- */

    public function test_switcher_persists_per_user(): void
    {
        [$user] = $this->userIn();
        [$other] = $this->userIn();
        $this->actingAs($user)->from('/dashboard')->post('/locale', ['locale' => 'ur'])->assertRedirect('/dashboard');
        $this->assertSame('ur', $user->fresh()->locale);
        $this->assertNull($other->fresh()->locale, 'other users are untouched');
        Tenant::forget();
        $this->actingAs($user)->get('/dashboard')->assertSee('dir="rtl"', false)->assertSee('ڈیش بورڈ');

        Tenant::forget();
        $this->actingAs($user)->from('/profile')->post('/locale', ['locale' => ''])->assertRedirect('/profile');
        $this->assertNull($user->fresh()->locale, 'empty = back to the workspace default');
    }

    public function test_guest_switch_is_kept_in_the_session(): void
    {
        $this->from('/login')->post('/locale', ['locale' => 'ar'])->assertRedirect('/login')->assertSessionHas('locale', 'ar');
        $this->get('/login')->assertSee('dir="rtl"', false)->assertSee('تسجيل الدخول');
    }

    /** @return array<string, array{0: mixed}> */
    public static function badLocales(): array
    {
        return ['unsupported' => ['fr'], 'traversal' => ['../../../etc/passwd'], 'lang path' => ['en/../ar'], 'script' => ['<script>alert(1)</script>'], 'array' => [['ar']], 'long' => [str_repeat('x', 300)]];
    }

    #[DataProvider('badLocales')]
    public function test_invalid_locale_is_rejected(mixed $bad): void
    {
        [$user] = $this->userIn();
        $this->actingAs($user)->from('/profile')->post('/locale', ['locale' => $bad])->assertRedirect('/profile')->assertSessionHasErrors('locale');
        $this->assertNull($user->fresh()->locale);
        $this->assertNull(session('locale'));
    }

    public function test_switcher_never_redirects_off_site(): void
    {
        $this->post('/locale', ['locale' => 'ar'], ['Referer' => 'https://evil.example/phish'])->assertRedirect(url('/'));
    }

    public function test_switcher_requires_csrf_token(): void
    {
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->post('/locale', ['locale' => 'ar'])->assertStatus(419);
        $this->assertNull(session('locale'));
    }

    public function test_switcher_markup_is_accessible(): void
    {
        [$user] = $this->userIn();
        foreach (['/dashboard', '/profile'] as $url) {
            Tenant::forget();
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/<label for="locale-compact">Language<\/label>/', $html, $url);
            $this->assertStringContainsString('<select id="locale-compact" name="locale"', $html);
            $this->assertStringContainsString('<option value="ur" lang="ur" dir="rtl"', $html);
            $this->assertStringContainsString('>اردو</option>', $html);
            $this->assertStringContainsString('<option value="ar" lang="ar" dir="rtl"', $html);
            $this->assertStringContainsString('>العربية</option>', $html);
            $this->assertStringContainsString('<option value="en" lang="en" dir="ltr" selected', $html);
            $this->assertStringContainsString('action="'.route('locale.update').'"', $html);
            $this->assertMatchesRegularExpression('/action="[^"]*\/locale"[^>]*>\s*<input type="hidden" name="_token"/', $html);
        }
        $profile = $this->actingAs($user)->get('/profile')->getContent();
        $this->assertStringContainsString('<label for="locale-profile">', $profile);
        $this->assertStringContainsString('<option value="" selected>Workspace default</option>', $profile);
    }

    public function test_guest_pages_carry_the_compact_switcher(): void
    {
        $login = $this->get('/login')->assertOk()->getContent();
        $this->assertStringContainsString('<label for="locale-compact">Language</label>', $login);
        $this->assertStringContainsString('<option value="ar" lang="ar" dir="rtl"', $login);
    }

    /* ---------- Workspace default & tenancy ---------- */

    public function test_admin_sets_workspace_default_language(): void
    {
        [$user, $ws] = $this->userIn();
        $this->actingAs($user)->post('/settings', ['name' => $ws->name, 'language' => 'ar'])->assertSessionHasNoErrors();
        $this->assertSame('ar', $ws->fresh()->setting('language'));
        Tenant::forget();
        $this->actingAs($user)->post('/settings', ['name' => $ws->name, 'language' => '../../ur'])->assertSessionHasErrors('language');
        $this->assertSame('ar', $ws->fresh()->setting('language'));
        Tenant::forget();
        $this->actingAs($user)->get('/settings')->assertSee('name="language"', false)->assertSee('<option value="ar" lang="ar" selected', false);
    }

    public function test_workspace_default_never_leaks_across_tenants(): void
    {
        [$alice, $wsA] = $this->userIn('ar');
        [$bob, $wsB] = $this->userIn();

        $this->actingAs($bob)->get('/dashboard')->assertSee('<html lang="en" dir="ltr">', false);
        Tenant::forget();
        $this->actingAs($alice)->get('/dashboard')->assertSee('<html lang="ar" dir="rtl">', false);
        Tenant::forget();

        // A member of both follows only the workspace they are acting in.
        $wsA->members()->attach($bob->id, ['role' => 'member']);
        $bob->forceFill(['current_workspace_id' => $wsB->id])->save();
        $this->actingAs($bob->fresh())->get('/dashboard')->assertSee('<html lang="en" dir="ltr">', false);
        Tenant::forget();
        $bob->forceFill(['current_workspace_id' => $wsA->id])->save();
        $this->actingAs($bob->fresh())->get('/dashboard')->assertSee('<html lang="ar" dir="rtl">', false);
        Tenant::forget();

        // Not a member: pointing current_workspace_id at A does not borrow A's language.
        $carol = User::factory()->create();
        $wsC = Workspace::createFor($carol, 'Carol Co');
        $carol->forceFill(['current_workspace_id' => $wsA->id])->save();
        $this->actingAs($carol->fresh())->get('/dashboard')->assertSee('<html lang="en" dir="ltr">', false);
        $this->assertSame($wsC->id, $carol->fresh()->current_workspace_id);
        Tenant::forget();

        $this->getJson("/widget/{$wsA->widget_key}/config")->assertJsonPath('locale', 'ar');
        Tenant::forget();
        $this->getJson("/widget/{$wsB->widget_key}/config")->assertJsonPath('locale', 'en');
    }

    /* ---------- Widget ---------- */

    public function test_widget_follows_host_or_configured_locale(): void
    {
        [, $ws] = $this->userIn('ur');
        $this->getJson("/widget/{$ws->widget_key}/config?locale=ar-SA")->assertOk()
            ->assertJsonPath('locale', 'ar')->assertJsonPath('dir', 'rtl')->assertJsonPath('lang', 'ar')
            ->assertJsonPath('strings.send', 'إرسال')->assertJsonPath('strings.placeholder', 'اكتب رسالتك…')
            ->assertJsonPath('greeting', 'مرحبًا! كيف يمكنني مساعدتك اليوم؟');
        Tenant::forget();
        $this->getJson("/widget/{$ws->widget_key}/config")->assertJsonPath('locale', 'ur')->assertJsonPath('strings.send', 'بھیجیں');
        Tenant::forget();
        $this->getJson("/widget/{$ws->widget_key}/config?locale=../../etc")->assertJsonPath('locale', 'ur');
        Tenant::forget();
        $ws->updateSettings(['language' => null]);
        $this->getJson("/widget/{$ws->widget_key}/config?locale=fr")->assertJsonPath('locale', 'en')->assertJsonPath('dir', 'ltr')
            ->assertJsonPath('strings.powered', 'Powered by '.config('saas.name'));
    }

    public function test_widget_script_sends_locale_and_inserts_strings_as_text(): void
    {
        $js = $this->get('/widget.js')->getContent();
        $this->assertStringContainsString("s.getAttribute('data-locale') || document.documentElement.lang", $js);
        $this->assertStringContainsString('encodeURIComponent(want.slice(0, 35))', $js);
        $this->assertStringContainsString('locale: cfg.locale', $js);
        $this->assertStringContainsString('textContent = tr.send', $js);
        $this->assertStringNotContainsString('innerHTML = tr', $js);
        $this->assertDoesNotMatchRegularExpression('/innerHTML\s*=\s*[^;]*(cfg\.(?!branding)|tr\.)/', $js);
        $this->assertStringContainsString('aria-expanded', $js);
    }

    /* ---------- Catalogues ---------- */

    public function test_catalogues_have_identical_keys_and_placeholders(): void
    {
        $en = $this->catalogue('en');
        $this->assertNotEmpty($en);
        foreach ($en as $k => $v) {
            if (preg_match('/^(quota|feature|event)\./', $k) === 1) {
                $this->assertNotSame('', $v); // namespaced keys disambiguate short labels

                continue;
            }
            if (! str_contains($v, '|')) {
                $this->assertSame($k, $v, 'en.json values are the source strings');
            }
        }
        foreach (['ur', 'ar'] as $locale) {
            $t = $this->catalogue($locale);
            $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($t))), "missing in $locale.json");
            $this->assertSame([], array_values(array_diff(array_keys($t), array_keys($en))), "extra in $locale.json");
            foreach ($t as $key => $value) {
                $isPlural = str_contains($value, '|') && str_contains($key, ':count');
                $isPlural ? $this->assertContains(':count', $this->placeholders($value), "$locale: plural lacks :count for $key") : $this->assertSame($this->placeholders($key), $this->placeholders($value), "$locale: placeholders differ for $key");
                $this->assertDoesNotMatchRegularExpression('/<[a-z\/!]/i', $value, "$locale: markup in $key");
                $this->assertMatchesRegularExpression('/\p{Arabic}/u', $value, "$locale: looks untranslated: $key");
            }
        }
    }

    public function test_every_translation_key_used_in_code_exists_in_en_json(): void
    {
        $en = $this->catalogue('en');
        $used = [];
        foreach (Finder::create()->files()->in([resource_path('views'), app_path()])->name('*.php') as $f) {
            preg_match_all("/(?:__|trans_choice|\\\$t)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $f->getContents(), $m);
            foreach ($m[1] as $key) {
                if (preg_match('/^[a-z_]+\.$/', $key) === 1) {
                    continue; // dynamic prefix, e.g. __('feature.'.$f)
                }
                $used[stripslashes($key)] = $f->getRelativePathname();
            }
        }
        $this->assertGreaterThan(80, count($used));
        foreach ($used as $key => $file) {
            $this->assertArrayHasKey($key, $en, "\"$key\" ($file) is missing from lang/en.json");
        }
        $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($used), self::DYNAMIC_KEYS, self::dynamicPrefixed($en))), 'unused keys in lang/en.json');
    }

    public function test_scoped_views_have_no_unwrapped_text(): void
    {
        foreach (self::SCOPED_VIEWS as $rel) {
            $src = file_get_contents(resource_path('views/'.$rel));
            $this->assertDoesNotMatchRegularExpression("/@section\\('(title|message)',\\s*'/", $src, "$rel: literal section text");
            $this->assertDoesNotMatchRegularExpression('/(placeholder|aria-label|title|alt)="(?!\{\{|\$\{)[^"]*[A-Za-z]{2}/', $src, "$rel: literal attribute text");
            $text = preg_replace([
                '/<pre\b.*?<\/pre>/s', '/<script\b.*?<\/script>/s', '/<style\b.*?<\/style>/s', '/\{\{--.*?--\}\}/s', '/\{\{.*?\}\}/s', '/\{!!.*?!!\}/s',
                '/@\w+\s*(\((?:[^()]++|(?1))*\))?/', '/<[^>]*>/s',
            ], ' ', $src);
            $text = html_entity_decode($text);
            $this->assertDoesNotMatchRegularExpression('/[A-Za-z]{2,}/', $text, "$rel has text outside __(): ".trim(preg_replace('/\s+/', ' ', $text)));
        }
    }

    public function test_translations_are_only_echoed_escaped(): void
    {
        foreach (Finder::create()->files()->in(resource_path('views'))->name('*.blade.php') as $f) {
            $this->assertDoesNotMatchRegularExpression('/\{!!\s*(__|trans|@lang)/', $f->getContents(), $f->getRelativePathname());
        }
        app()->setLocale('ar');
        $this->assertSame('&lt;b&gt;x&lt;/b&gt; &amp;', e(__('<b>x</b> &')));
    }

    public function test_migration_is_reversible(): void
    {
        $this->assertTrue(\Schema::hasColumn('users', 'locale'));
        $this->artisan('migrate:rollback', ['--step' => 1, '--path' => 'database/migrations/2026_10_29_000000_add_locale_to_users.php'])->assertSuccessful();
        $this->assertFalse(\Schema::hasColumn('users', 'locale'));
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_10_29_000000_add_locale_to_users.php'])->assertSuccessful();
        $this->assertTrue(\Schema::hasColumn('users', 'locale'));
    }

    public function test_billing_and_profile_pages_render_in_ur_and_ar_without_leaking_keys(): void
    {
        foreach (['ur' => 'موجودہ پلان', 'ar' => 'الخطة الحالية'] as $loc => $needle) {
            [$user] = $this->userIn(null, $loc);
            $html = $this->actingAs($user)->get('/billing')->assertOk()->assertSee($needle)->getContent();
            $this->assertStringNotContainsString('quota.', $html);
            $this->assertStringNotContainsString('feature.', $html);
            $this->assertStringContainsString('role="progressbar"', $html);
            $this->actingAs($user)->get('/profile/security')->assertOk()->assertDontSee('event.');
            $this->actingAs($user)->get('/profile')->assertOk()->assertSee('dir="'.($loc === 'ur' || $loc === 'ar' ? 'rtl' : 'ltr').'"', false);
        }
    }

    public function test_translated_billing_keeps_english_in_en(): void
    {
        [$user] = $this->userIn();
        $this->actingAs($user)->get('/billing')->assertOk()->assertSee('Current plan:')->assertSee('Messages');
    }
}
