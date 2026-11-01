<?php

namespace App\Brain\Knowledge;

use App\Brain\Exceptions\BrainException;
use Smalot\PdfParser\Parser as PdfParser;
use ZipArchive;

/** Turns business files into plain text: PDF, DOCX, XLSX, PPTX, CSV, HTML, MD, TXT, JSON. */
class TextExtractor
{
    public function extract(string $absolutePath, ?string $extension = null): string
    {
        $ext = strtolower($extension ?: pathinfo($absolutePath, PATHINFO_EXTENSION));

        $text = match ($ext) {
            'pdf' => $this->pdf($absolutePath),
            'docx' => $this->officeXml($absolutePath, ['word/document.xml'], '</w:p>'),
            'pptx' => $this->officeXml($absolutePath, $this->zipEntries($absolutePath, '#^ppt/slides/slide\d+\.xml$#'), '</a:p>'),
            'xlsx' => $this->xlsx($absolutePath),
            'html', 'htm' => $this->html(file_get_contents($absolutePath)),
            'csv', 'tsv' => $this->csv($absolutePath, $ext === 'tsv' ? "\t" : ','),
            'json' => json_encode(json_decode(file_get_contents($absolutePath), true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'txt', 'md', 'markdown', 'log', 'xml', 'yaml', 'yml', '' => file_get_contents($absolutePath),
            default => throw new BrainException("Unsupported file type .{$ext}"),
        };

        return $this->clean((string) $text);
    }

    public function html(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript|svg)[^>]*>.*?</\1>#si', ' ', $html);
        $html = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr)[^>]*>#i', "\n", $html);

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function clean(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text);
        $text = preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text);

        return trim($text);
    }

    protected function pdf(string $path): string
    {
        return (new PdfParser)->parseFile($path)->getText();
    }

    protected function zipEntries(string $path, string $pattern): array
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match($pattern, $name)) {
                $names[] = $name;
            }
        }
        $zip->close();
        natsort($names);

        return array_values($names);
    }

    protected function officeXml(string $path, array $entries, string $paragraphEnd): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new BrainException('Could not open office file.');
        }
        $out = [];
        foreach ($entries as $entry) {
            $xml = $zip->getFromName($entry);
            if ($xml !== false) {
                $out[] = strip_tags(str_replace($paragraphEnd, $paragraphEnd."\n", $xml));
            }
        }
        $zip->close();

        return html_entity_decode(implode("\n\n", $out), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    protected function xlsx(string $path): string
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $sx = simplexml_load_string($xml);
            foreach ($sx->si as $si) {
                $shared[] = trim(strip_tags($si->asXML()));
            }
        }
        $rows = [];
        foreach ($this->zipEntries($path, '#^xl/worksheets/sheet\d+\.xml$#') as $sheet) {
            $sx = simplexml_load_string($zip->getFromName($sheet));
            foreach ($sx->sheetData->row ?? [] as $row) {
                $cells = [];
                foreach ($row->c as $c) {
                    $v = (string) $c->v;
                    $cells[] = ((string) $c['t']) === 's' ? ($shared[(int) $v] ?? '') : (((string) $c['t']) === 'inlineStr' ? strip_tags($c->is->asXML()) : $v);
                }
                $rows[] = implode(' | ', $cells);
            }
            $rows[] = '';
        }
        $zip->close();

        return implode("\n", $rows);
    }

    protected function csv(string $path, string $sep): string
    {
        $fh = fopen($path, 'r');
        $header = fgetcsv($fh, 0, $sep, '"', '\\');
        $lines = [];
        while (($row = fgetcsv($fh, 0, $sep, '"', '\\')) !== false) {
            // "Column: value" lines keep meaning when a row lands in its own chunk
            $pairs = [];
            foreach ($row as $i => $value) {
                $pairs[] = ($header[$i] ?? "col{$i}").': '.$value;
            }
            $lines[] = implode('; ', $pairs);
        }
        fclose($fh);

        return implode("\n", $lines);
    }
}
