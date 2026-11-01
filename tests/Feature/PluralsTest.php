<?php

namespace Tests\Feature;

use Tests\TestCase;

class PluralsTest extends TestCase
{
    public function test_english_forms(): void
    {
        app()->setLocale('en');
        $this->assertSame('No leads', trans_choice(':count leads', 0));
        $this->assertSame('1 lead', trans_choice(':count leads', 1));
        $this->assertSame('5 documents', trans_choice(':count documents', 5));
        $this->assertSame('1 action', trans_choice(':count actions', 1));
    }

    public function test_arabic_dual_and_few_many(): void
    {
        app()->setLocale('ar');
        $this->assertSame('مستندان', trans_choice(':count documents', 2));
        $this->assertSame('5 مستندات', trans_choice(':count documents', 5));
        $this->assertSame('12 مستندًا', trans_choice(':count documents', 12));
    }

    public function test_urdu_singular(): void
    {
        app()->setLocale('ur');
        $this->assertSame('1 دستاویز', trans_choice(':count documents', 1));
        $this->assertSame('3 دستاویزات', trans_choice(':count documents', 3));
    }
}
