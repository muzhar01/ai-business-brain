<?php

namespace App\Brain\Knowledge;

/** Splits text on paragraph, then sentence, then hard boundaries, with overlap. */
class Chunker
{
    public function __construct(
        protected int $size = 1200,
        protected int $overlap = 200,
    ) {}

    /** @return array<int, string> */
    public function split(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        if (mb_strlen($text) <= $this->size) {
            return [$text];
        }

        $pieces = $this->atoms($text);
        $chunks = [];
        $current = '';

        foreach ($pieces as $piece) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($piece) + 1 > $this->size) {
                $chunks[] = trim($current);
                $current = $this->tail($current).' ';
            }
            $current .= $piece.' ';
        }
        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return array_values(array_filter($chunks, fn ($c) => mb_strlen($c) > 20 || count($chunks) === 1));
    }

    /** Paragraphs, falling back to sentences, falling back to fixed slices. */
    protected function atoms(string $text): array
    {
        $atoms = [];
        foreach (preg_split("/\n\s*\n/", $text) as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            if (mb_strlen($paragraph) <= $this->size) {
                $atoms[] = $paragraph;

                continue;
            }
            foreach (preg_split('/(?<=[.!?。])\s+|\n/u', $paragraph) as $sentence) {
                if (mb_strlen($sentence) <= $this->size) {
                    $atoms[] = $sentence;
                } else {
                    $step = max(1, $this->size - $this->overlap);
                    for ($i = 0; $i < mb_strlen($sentence); $i += $step) {
                        $atoms[] = mb_substr($sentence, $i, $this->size);
                    }
                }
            }
        }

        return $atoms;
    }

    protected function tail(string $text): string
    {
        if ($this->overlap <= 0) {
            return '';
        }
        $tail = mb_substr($text, -$this->overlap);
        $space = mb_strpos($tail, ' ');

        return $space === false ? $tail : mb_substr($tail, $space + 1);
    }
}
