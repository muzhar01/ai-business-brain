<?php

namespace App\Etl;

use ZipArchive;

/** Dependency-free XLSX writer (inline strings, one sheet). */
class XlsxWriter
{
    public static function write(string $path, array $headers, iterable $rows): void
    {
        $sheet = fopen('php://temp', 'w+');
        fwrite($sheet, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');
        $r = 1;
        fwrite($sheet, self::row($r++, $headers));
        foreach ($rows as $row) {
            fwrite($sheet, self::row($r++, array_values($row)));
        }
        fwrite($sheet, '</sheetData></worksheet>');
        rewind($sheet);

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Export" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', stream_get_contents($sheet));
        $zip->close();
        fclose($sheet);
    }

    protected static function row(int $r, array $cells): string
    {
        $xml = '<row r="'.$r.'">';
        foreach (array_values($cells) as $i => $v) {
            $ref = self::letters($i).$r;
            if (is_int($v) || is_float($v)) {
                $xml .= '<c r="'.$ref.'"><v>'.$v.'</v></c>';
            } else {
                $v = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v;
                $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v);
                $xml .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
            }
        }

        return $xml.'</row>';
    }

    public static function letters(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26).$s;
        }

        return $s;
    }
}
