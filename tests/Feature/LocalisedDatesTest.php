<?php

namespace Tests\Feature;

use App\Support\Format;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LocalisedDatesTest extends TestCase
{
    public function test_dates_follow_the_active_locale(): void
    {
        $d = Carbon::parse('2026-10-31 14:05:00');
        app()->setLocale('en');
        $this->assertSame('Oct 31, 2026', Format::date($d));
        $this->assertSame('Oct 31', Format::shortDate($d));
        $this->assertSame('Oct 31, 14:05', Format::dateTime($d));
        app()->setLocale('ar');
        $this->assertStringContainsString('أكتوبر', Format::date($d));
        app()->setLocale('ur');
        $this->assertStringContainsString('اکتوبر', Format::date($d));
        app()->setLocale('en');
    }

    public function test_null_dates_render_empty(): void
    {
        $this->assertSame('', Format::date(null));
        $this->assertSame('', Format::shortDate(null));
        $this->assertSame('', Format::dateTime(null));
    }

    public function test_views_no_longer_hardcode_english_date_formats(): void
    {
        foreach (['app/admin', 'app/imports/create', 'app/settings/data', 'app/tasks'] as $v) {
            $src = file_get_contents(resource_path("views/$v.blade.php"));
            $this->assertDoesNotMatchRegularExpression("/->format\('M|toFormattedDateString/", $src, $v);
        }
    }
}
