<?php

namespace Tests\Unit;

use App\Etl\Transformer;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Exhaustive, seeded matrix over every ETL transform step (~2,000 cases). */
class TransformerMatrixTest extends TestCase
{
    public static function numbers(): iterable
    {
        mt_srand(1001);
        for ($i = 0; $i < 120; $i++) {
            $n = round(mt_rand(0, 99999999) / 100, 2);
            $int = (int) floor($n);
            $cents = str_pad((string) round(($n - $int) * 100), 2, '0', STR_PAD_LEFT);
            yield "plain {$i}" => ["{$int}.{$cents}", (float) "{$int}.{$cents}"];
            yield "us thousands {$i}" => [number_format($n, 2, '.', ','), round($n, 2)];
            yield "eu thousands {$i}" => [number_format($n, 2, ',', '.'), round($n, 2)];
            yield "currency {$i}" => ['PKR '.number_format($n, 2, '.', ','), round($n, 2)];
            yield "dollar {$i}" => ['$'.number_format($n, 2), round($n, 2)];
            yield "negative {$i}" => ['-'.number_format($n, 2, '.', ''), -round($n, 2)];
            yield "integer {$i}" => [(string) $int, (float) $int];
        }
        yield 'blank' => ['', null];
        yield 'spaces' => ['   ', null];
        yield 'text' => ['abc', null];
        yield 'eu short' => ['12,5', 12.5];
    }

    #[DataProvider('numbers')]
    public function test_number(string $input, ?float $expected): void
    {
        $actual = Transformer::apply($input, 'number');
        $expected === null ? $this->assertNull($actual) : $this->assertEqualsWithDelta($expected, $actual, 0.001);
    }

    public static function dates(): iterable
    {
        mt_srand(2002);
        for ($i = 0; $i < 70; $i++) {
            $d = Carbon::create(1995, 1, 1)->addDays(mt_rand(0, 13000));
            $iso = $d->format('Y-m-d');
            yield "iso {$i}" => [$iso, $iso];
            yield "dmy dash {$i}" => [$d->format('d-m-Y'), $iso];
            yield "dmy slash {$i}" => [$d->format('d/m/Y'), $iso];
            yield "long {$i}" => [$d->format('j F Y'), $iso];
            yield "us words {$i}" => [$d->format('M j, Y'), $iso];
            yield "excel {$i}" => [(string) Carbon::create(1899, 12, 30)->diffInDays($d), $iso];
            yield "datetime {$i}" => [$d->format('Y-m-d').' 13:45:00', $iso];
        }
        yield 'blank' => ['', null];
        yield 'garbage' => ['not a date', null];
    }

    #[DataProvider('dates')]
    public function test_date(string $input, ?string $expected): void
    {
        $this->assertSame($expected, Transformer::apply($input, 'date'));
    }

    public static function emails(): iterable
    {
        $valid = ['a@b.co', 'sara@nova.pk', 'first.last@company.com', 'x+tag@gmail.com', 'UPPER@DOMAIN.ORG', ' padded@site.io ', 'n1@sub.domain.co.uk', 'o\'brien@ie.ie', 'a_b@c-d.com', '123@numbers.net'];
        $invalid = ['', 'plain', '@no-local.com', 'no-at.com', 'a@', 'a@b', 'two@@at.com', 'spa ce@x.com', 'a@.com', 'comma,@x.com'];
        foreach (['', 'trim', 'trim|lower'] as $prefix) {
            foreach ($valid as $i => $e) {
                yield "valid {$prefix} {$i}" => [$e, $prefix, mb_strtolower(trim($e))];
            }
            foreach ($invalid as $i => $e) {
                yield "invalid {$prefix} {$i}" => [$e, $prefix, null];
            }
        }
    }

    #[DataProvider('emails')]
    public function test_email(string $input, string $prefix, ?string $expected): void
    {
        $this->assertSame($expected, Transformer::apply($input, trim($prefix.'|email', '|')));
    }

    public static function phones(): iterable
    {
        mt_srand(3003);
        for ($i = 0; $i < 40; $i++) {
            $digits = (string) mt_rand(3000000000, 3499999999);
            yield "intl {$i}" => ['+92 '.substr($digits, 0, 3).' '.substr($digits, 3), '+92'.$digits];
            yield "dashed {$i}" => ['0'.substr($digits, 0, 3).'-'.substr($digits, 3), '0'.$digits];
            yield "parens {$i}" => ['(0'.substr($digits, 0, 3).') '.substr($digits, 3, 3).'.'.substr($digits, 6), '0'.$digits];
        }
        yield 'empty' => ['', null];
        yield 'letters' => ['call me', null];
    }

    #[DataProvider('phones')]
    public function test_phone(string $input, ?string $expected): void
    {
        $this->assertSame($expected, Transformer::apply($input, 'phone'));
    }

    public static function booleans(): iterable
    {
        foreach (['1', 'true', 'TRUE', 'yes', 'Yes', 'y', 'on', 'x', ' true '] as $v) {
            yield "true {$v}" => [$v, true];
        }
        foreach (['0', 'false', 'no', 'n', 'off', 'maybe', ''] as $v) {
            yield "false {$v}" => [$v, false];
        }
    }

    #[DataProvider('booleans')]
    public function test_boolean(string $input, bool $expected): void
    {
        $this->assertSame($expected, Transformer::apply($input, 'boolean'));
    }

    public static function strings(): iterable
    {
        $samples = ['hello world', 'ÇAĞRI Öztürk', 'محمد علی', 'naïve café', 'MiXeD CaSe', '  spaced  ', 'ünïcödé', 'Ελληνικά', '日本語テキスト', 'emoji 😀 ok'];
        foreach ($samples as $i => $s) {
            yield "lower {$i}" => [$s, 'lower', mb_strtolower($s)];
            yield "upper {$i}" => [$s, 'upper', mb_strtoupper($s)];
            yield "trim {$i}" => [$s, 'trim', trim($s)];
            yield "truncate {$i}" => [$s, 'truncate:5', mb_substr($s, 0, 5)];
            yield "prefix {$i}" => [$s, 'prefix:ID-', 'ID-'.$s];
            yield "suffix {$i}" => [$s, 'suffix: PKR', $s.' PKR'];
            yield "trim+upper {$i}" => [$s, 'trim|upper', mb_strtoupper(trim($s))];
            yield "strip {$i}" => ['<b>'.$s.'</b>', 'strip_html', trim($s)];
            yield "replace {$i}" => [$s, 'replace: ,_', str_replace(' ', '_', $s)];
            yield "digits {$i}" => [$s.' 42', 'digits', preg_replace('/\D/', '', $s.' 42')];
        }
    }

    #[DataProvider('strings')]
    public function test_string_steps(string $input, string $pipeline, string $expected): void
    {
        $this->assertSame($expected, Transformer::apply($input, $pipeline));
    }

    public static function lookups(): iterable
    {
        $map = 'map:Closed Won=won,Closed Lost=lost,In Progress=qualified,New Lead=new';
        foreach (['Closed Won' => 'won', 'closed won' => 'won', 'CLOSED LOST' => 'lost', 'In Progress' => 'qualified', 'New Lead' => 'new', 'Unknown' => 'Unknown'] as $in => $out) {
            yield "map {$in}" => [$in, $map, $out];
        }
        foreach (['a,b,c' => ['split:,:0', 'a'], 'a,b,c ' => ['split:,:2', 'c'], 'x;y' => ['split:;:1', 'y'], 'one' => ['split:,:3', null]] as $in => [$p, $out]) {
            yield "split {$in} {$p}" => [$in, $p, $out];
        }
        foreach (['n/a', 'N/A', 'null', '-', 'none', 'NA', ''] as $v) {
            yield "nullify {$v}" => [$v, 'nullify', null];
        }
        foreach (['real', '0', 'n/a!'] as $v) {
            yield "keep {$v}" => [$v, 'nullify', $v];
        }
    }

    #[DataProvider('lookups')]
    public function test_lookups(string $input, string $pipeline, ?string $expected): void
    {
        $this->assertSame($expected, Transformer::apply($input, $pipeline));
    }

    public static function templates(): iterable
    {
        mt_srand(4004);
        $first = ['Sara', 'Ali', 'Ayesha', 'Bilal', 'Zara', 'Omar', 'Hina', 'Usman'];
        $last = ['Khan', 'Ahmed', 'Malik', 'Raza', 'Iqbal', 'Shah'];
        for ($i = 0; $i < 60; $i++) {
            $f = $first[mt_rand(0, 7)];
            $l = $last[mt_rand(0, 5)];
            yield "name {$i}" => [['First' => " {$f} ", 'Last' => $l], '{First} {Last}', "{$f} {$l}"];
            yield "missing last {$i}" => [['First' => $f], '{First} {Last}', $f];
            yield "with literal {$i}" => [['City' => 'Lahore', 'Code' => (string) $i], '{City}-{Code}', "Lahore-{$i}"];
        }
        yield 'plain column' => [['Email' => 'x@y.z'], 'Email', 'x@y.z'];
        yield 'missing column' => [['Email' => 'x@y.z'], 'Phone', null];
        yield 'null source' => [['a' => 'b'], null, null];
    }

    #[DataProvider('templates')]
    public function test_source_templates(array $row, ?string $source, ?string $expected): void
    {
        $this->assertSame($expected, Transformer::source($row, $source));
    }

    public static function nulls(): iterable
    {
        foreach (['trim', 'lower', 'upper', 'title', 'slug', 'number', 'integer', 'date', 'datetime', 'phone', 'email', 'digits', 'prefix:x', 'suffix:y', 'truncate:3', 'map:a=b', 'split:,:0', 'strip_html', 'replace:a,b'] as $step) {
            yield $step => [$step];
        }
    }

    #[DataProvider('nulls')]
    public function test_null_passes_through_every_step(string $step): void
    {
        $this->assertNull(Transformer::apply(null, $step));
    }
}
