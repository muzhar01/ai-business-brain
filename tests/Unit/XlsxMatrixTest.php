<?php

namespace Tests\Unit;

use App\Etl\SourceReader;
use App\Etl\XlsxReader;
use App\Etl\XlsxWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Write → read round trips on seeded random sheets. */
class XlsxMatrixTest extends TestCase
{
    public static function sheets(): iterable
    {
        mt_srand(8008);
        $words = ['Solar', 'Panel', 'ٹیسٹ', 'Café', '中文', 'O\'Neil', '"quoted"', '<tag>', 'a&b', '=SUM(A1)', 'line'."\n".'break', '  spaced', 'ñ', '😀'];
        for ($i = 0; $i < 300; $i++) {
            $cols = mt_rand(1, 12);
            $rows = mt_rand(1, 25);
            $headers = [];
            for ($c = 0; $c < $cols; $c++) {
                $headers[] = 'col_'.$c.'_'.$words[mt_rand(0, 4)];
            }
            $data = [];
            for ($r = 0; $r < $rows; $r++) {
                $line = [];
                for ($c = 0; $c < $cols; $c++) {
                    $line[] = match (mt_rand(0, 3)) {
                        0 => mt_rand(-100000, 100000),
                        1 => round(mt_rand(0, 1000000) / 100, 2),
                        2 => $words[mt_rand(0, count($words) - 1)].' '.mt_rand(1, 999),
                        3 => '',
                    };
                }
                $data[] = $line;
            }
            yield "sheet {$i} ({$cols}x{$rows})" => [$headers, $data];
        }
    }

    #[DataProvider('sheets')]
    public function test_round_trip(array $headers, array $rows): void
    {
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        XlsxWriter::write($path, $headers, $rows);
        $read = iterator_to_array(app(SourceReader::class)->fromFile($path, 'xlsx'), false);
        @unlink($path);

        $nonEmpty = array_values(array_filter($rows, fn ($r) => count(array_filter($r, fn ($v) => $v !== '')) > 0));
        $this->assertCount(count($nonEmpty), $read);
        foreach ($nonEmpty as $i => $row) {
            foreach ($headers as $c => $h) {
                $this->assertEquals((string) $row[$c], (string) ($read[$i][$h] ?? ''), "row {$i} col {$h}");
            }
        }
    }

    public static function columns(): iterable
    {
        foreach (['A' => 0, 'B' => 1, 'Z' => 25, 'AA' => 26, 'AZ' => 51, 'BA' => 52, 'ZZ' => 701, 'AAA' => 702, 'XFD' => 16383] as $letters => $index) {
            yield $letters => [$letters, $index];
        }
    }

    #[DataProvider('columns')]
    public function test_column_letters(string $letters, int $index): void
    {
        $this->assertSame($index, XlsxReader::columnIndex($letters));
        $this->assertSame($letters, XlsxWriter::letters($index));
    }
}
