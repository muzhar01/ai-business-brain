<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class DuplicationTest extends TestCase
{
    private const WINDOW = 12;

    private const MAX_DUPLICATE_WINDOWS = 0;

    private function windows(): array
    {
        $seen = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app'));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }
            $lines = [];
            foreach (file($f->getPathname()) as $n => $l) {
                $t = trim($l);
                if ($t === '' || in_array($t, ['{', '}', '});', '];', '],', ')'], true)
                    || str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*')
                    || str_starts_with($t, 'use ') || str_starts_with($t, 'namespace ')) {
                    continue;
                }
                $lines[] = [$n + 1, $t];
            }
            for ($i = 0; $i + self::WINDOW <= count($lines); $i++) {
                $slice = array_slice($lines, $i, self::WINDOW);
                $key = md5(implode("\n", array_column($slice, 1)));
                $seen[$key][] = basename($f->getPathname()).':'.$slice[0][0];
            }
        }

        return $seen;
    }

    public function test_duplicated_code_stays_under_baseline(): void
    {
        $dups = array_filter($this->windows(), fn ($p) => count(array_unique($p)) > 1);
        $this->assertLessThanOrEqual(
            self::MAX_DUPLICATE_WINDOWS,
            count($dups),
            'Duplicated '.self::WINDOW.'-line blocks grew; extract shared code. Examples: '.json_encode(array_slice(array_values($dups), 0, 5))
        );
    }

    public function test_detector_finds_a_known_duplicate(): void
    {
        $a = array_map(fn ($i) => "\$x$i = $i;", range(1, 12));
        $this->assertSame(md5(implode("\n", $a)), md5(implode("\n", $a)));
    }
}
