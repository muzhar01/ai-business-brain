<?php

namespace Tests\Unit;

use App\Etl\AutoMapper;
use App\Etl\Targets;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Every synonym of every target field, in every header style real files use. */
class AutoMapperMatrixTest extends TestCase
{
    public static function headers(): iterable
    {
        foreach (['leads', 'tasks', 'knowledge'] as $target) {
            foreach (Targets::fields($target) as $field => $meta) {
                foreach (array_unique(array_merge([$field], $meta['synonyms'] ?? [])) as $syn) {
                    $styles = [
                        'as-is' => $syn,
                        'upper' => mb_strtoupper($syn),
                        'title' => Str::title($syn),
                        'snake' => Str::snake(str_replace('-', ' ', $syn)),
                        'kebab' => Str::slug($syn),
                        'studly' => Str::studly($syn),
                        'padded' => '  '.$syn.'  ',
                    ];
                    foreach ($styles as $style => $header) {
                        yield "{$target}.{$field} <- {$syn} ({$style})" => [$target, $field, $header];
                    }
                }
            }
        }
    }

    #[DataProvider('headers')]
    public function test_header_maps_to_field(string $target, string $field, string $header): void
    {
        $mapping = collect(app(AutoMapper::class)->suggest($target, [$header], [], false))->keyBy('target');
        $this->assertSame($header, $mapping[$field]['source'], "\"{$header}\" should map to {$target}.{$field}");
    }

    public static function datasetHeaders(): iterable
    {
        $cases = ['Product Name' => 'product_name', 'SKU' => 'sku', 'Unit Price (PKR)' => 'unit_price_pkr', 'stock-level' => 'stock_level', 'meta.brand' => 'meta_brand', 'Größe' => 'grosse', 'createdAt' => 'created_at', '  spaced  ' => 'spaced', 'A/B Test' => 'a_b_test', 'qty#' => 'qty'];
        foreach ($cases as $in => $out) {
            yield $in => [$in, $out];
        }
    }

    #[DataProvider('datasetHeaders')]
    public function test_dataset_field_names_are_normalised(string $header, string $expected): void
    {
        $this->assertSame($expected, app(AutoMapper::class)->suggest('dataset', [$header])[0]['target']);
    }

    public function test_unrelated_headers_stay_unmapped(): void
    {
        $mapping = collect(app(AutoMapper::class)->suggest('leads', ['zzz', 'qwerty', '42'], [], false));
        $this->assertTrue($mapping->every(fn ($m) => $m['source'] === null));
    }
}
