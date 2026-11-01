<?php

namespace App\Etl;

use App\Brain\Data\Message;
use App\Brain\LlmManager;
use Illuminate\Support\Str;
use Throwable;

/**
 * Suggests a column mapping: exact/synonym/fuzzy matching first, then
 * (optionally) asks the workspace's LLM to fill gaps from sample rows.
 */
class AutoMapper
{
    public function __construct(protected LlmManager $llm) {}

    /** @return array<int, array{source: ?string, target: string, transform: ?string, default: ?string}> */
    public function suggest(string $target, array $columns, array $sampleRows = [], bool $useAi = true): array
    {
        if ($target === 'dataset') {
            return array_map(fn ($c) => ['source' => $c, 'target' => self::fieldName($c), 'transform' => 'trim', 'default' => null], $columns);
        }

        $fields = Targets::fields($target);
        $mapping = [];
        $used = [];

        // Split names (First + Last) become a template for "name" unless a full-name column exists.
        $nameTemplate = null;
        if (isset($fields['name'])) {
            $first = collect($columns)->first(fn ($c) => preg_match('/^(first|given)[ _-]?name$/i', $c));
            $last = collect($columns)->first(fn ($c) => preg_match('/^(last|sur|family)[ _-]?name$/i', $c));
            $hasFull = collect($columns)->contains(fn ($c) => $this->score($c, 'name', $fields['name']['synonyms'] ?? []) >= 1.0);
            if ($first && ! $hasFull) {
                $nameTemplate = '{'.$first.'}'.($last ? ' {'.$last.'}' : '');
                $used = array_values(array_filter([$first, $last]));
            }
        }

        // Global best-first assignment: score every (field, column) pair and take the strongest
        // matches first, so "Contact Number" goes to phone (exact) rather than name (partial).
        $pairs = [];
        foreach ($fields as $field => $meta) {
            foreach ($columns as $column) {
                if (! in_array($column, $used, true) && ($score = $this->score($column, $field, $meta['synonyms'] ?? [])) >= 0.72) {
                    $pairs[] = [$score, $field, $column];
                }
            }
        }
        usort($pairs, fn ($a, $b) => $b[0] <=> $a[0]);
        $chosen = [];
        foreach ($pairs as [$score, $field, $column]) {
            if (! isset($chosen[$field]) && ! in_array($column, $used, true)) {
                $chosen[$field] = $column;
                $used[] = $column;
            }
        }
        foreach ($fields as $field => $meta) {
            $mapping[$field] = ['source' => $chosen[$field] ?? null, 'target' => $field, 'transform' => $this->defaultTransform($meta), 'default' => null];
        }

        if ($nameTemplate) {
            $mapping['name']['source'] = $nameTemplate;
        }

        $missing = collect($mapping)->filter(fn ($m) => $m['source'] === null)->keys()->all();
        if ($useAi && $missing && $sampleRows) {
            foreach ($this->askLlm($target, $missing, $columns, $sampleRows, $used) as $field => $column) {
                if (isset($mapping[$field]) && in_array($column, $columns, true)) {
                    $mapping[$field]['source'] = $column;
                }
            }
        }

        return array_values($mapping);
    }

    /** "Unit Price (PKR)" -> unit_price_pkr, "createdAt" -> created_at, "SKU" -> sku */
    public static function fieldName(string $header): string
    {
        $s = Str::ascii(trim($header));
        $s = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $s);
        $s = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($s)), '_');

        return $s !== '' ? $s : 'field';
    }

    protected function score(string $column, string $field, array $synonyms): float
    {
        $c = $this->norm($column);
        $candidates = array_map(fn ($s) => $this->norm($s), array_merge([$field], $synonyms));
        $best = 0.0;
        foreach ($candidates as $cand) {
            if ($c === $cand) {
                return 1.0;
            }
            if ($cand !== '' && (str_contains($c, $cand) || str_contains($cand, $c)) && min(strlen($c), strlen($cand)) >= 3) {
                $best = max($best, 0.85);
            }
            similar_text($c, $cand, $pct);
            $best = max($best, $pct / 100);
        }

        return $best;
    }

    protected function norm(string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(Str::ascii($s)));
    }

    protected function defaultTransform(array $meta): string
    {
        return trim('trim|nullify'.(isset($meta['transform']) ? '|'.$meta['transform'] : ''), '|');
    }

    protected function askLlm(string $target, array $missing, array $columns, array $sampleRows, array $used): array
    {
        try {
            $available = array_values(array_diff($columns, $used));
            if (! $available || ! $this->llm->available()) {
                return [];
            }
            $prompt = "Map spreadsheet columns to {$target} fields. Fields still unmapped: ".implode(', ', $missing)
                .".\nAvailable columns: ".json_encode($available)
                ."\nSample rows: ".json_encode(array_slice($sampleRows, 0, 3), JSON_UNESCAPED_UNICODE)
                ."\nReply with ONLY a JSON object {\"field\": \"column\"} for fields you are confident about. Omit the rest.";

            $reply = $this->llm->provider()->chat([Message::system('You are a precise data-mapping assistant. Output JSON only.'), Message::user($prompt)], [], ['max_tokens' => 400]);
            preg_match('/\{.*\}/s', (string) $reply->content, $m);

            return is_array($json = json_decode($m[0] ?? '{}', true)) ? $json : [];
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }
}
