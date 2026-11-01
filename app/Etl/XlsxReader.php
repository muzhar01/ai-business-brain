<?php

namespace App\Etl;

use RuntimeException;
use ZipArchive;

/** Dependency-free XLSX reader: first sheet (or by index), returns rows of cells in column order. */
class XlsxReader
{
    public static function rows(string $path, int $sheet = 1): \Generator
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Cannot open XLSX file.');
        }

        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $sx = simplexml_load_string($xml);
            foreach ($sx->si as $si) {
                $shared[] = self::text($si);
            }
        }

        $xml = $zip->getFromName("xl/worksheets/sheet{$sheet}.xml");
        $zip->close();
        if ($xml === false) {
            throw new RuntimeException("Sheet {$sheet} not found.");
        }

        $sx = simplexml_load_string($xml);
        foreach ($sx->sheetData->row ?? [] as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $index = self::columnIndex(preg_replace('/\d+/', '', (string) $c['r']));
                $type = (string) $c['t'];
                $value = match ($type) {
                    's' => $shared[(int) $c->v] ?? '',
                    'inlineStr' => self::text($c->is),
                    'b' => ((string) $c->v) === '1' ? 'true' : 'false',
                    default => (string) $c->v,
                };
                $cells[$index] = $value;
            }
            if ($cells) {
                $max = max(array_keys($cells));
                $line = [];
                for ($i = 0; $i <= $max; $i++) {
                    $line[] = $cells[$i] ?? '';
                }
                yield $line;
            }
        }
    }

    /** Concatenate every <t> run exactly (keeps leading spaces and line breaks; skips phonetic <rPh>). */
    protected static function text(\SimpleXMLElement $node): string
    {
        $out = '';
        foreach ($node->xpath('./*[local-name()="t"] | ./*[local-name()="r"]/*[local-name()="t"]') ?: [] as $t) {
            $out .= (string) $t;
        }

        return $out;
    }

    public static function columnIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return max(0, $n - 1);
    }
}
