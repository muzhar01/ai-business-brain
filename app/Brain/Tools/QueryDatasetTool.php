<?php

namespace App\Brain\Tools;

use App\Brain\Contracts\Tool;
use App\Models\Conversation;
use App\Models\Dataset;
use App\Models\DatasetRow;

/** Lets the brain answer questions over imported business tables (products, stock, orders...). */
class QueryDatasetTool implements Tool
{
    public function name(): string
    {
        return 'query_dataset';
    }

    public function description(): string
    {
        return 'Query an imported business dataset (products, inventory, orders, price lists...). Filter rows, sort, and aggregate (count, sum, avg, min, max), optionally grouped by a field.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'dataset' => ['type' => 'string', 'description' => 'Dataset slug'],
                'filters' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'field' => ['type' => 'string'],
                    'op' => ['type' => 'string', 'enum' => ['=', '!=', '>', '<', '>=', '<=', 'contains']],
                    'value' => ['type' => 'string'],
                ], 'required' => ['field', 'op', 'value']]],
                'aggregate' => ['type' => 'string', 'enum' => ['none', 'count', 'sum', 'avg', 'min', 'max']],
                'field' => ['type' => 'string', 'description' => 'Field to aggregate'],
                'group_by' => ['type' => 'string'],
                'sort_by' => ['type' => 'string'],
                'sort_desc' => ['type' => 'boolean'],
                'limit' => ['type' => 'integer'],
            ],
            'required' => ['dataset'],
        ];
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function handle(array $arguments, ?Conversation $conversation = null): string|array
    {
        $dataset = Dataset::where('slug', $arguments['dataset'])->orWhere('name', $arguments['dataset'])->first();
        if (! $dataset) {
            return 'Unknown dataset. Available: '.Dataset::pluck('slug')->implode(', ');
        }

        $rows = DatasetRow::where('dataset_id', $dataset->id)->limit(20000)->pluck('data');

        foreach ($arguments['filters'] ?? [] as $f) {
            $rows = $rows->filter(fn ($r) => self::matches($r[$f['field']] ?? null, $f['op'], $f['value']));
        }

        $aggregate = $arguments['aggregate'] ?? 'none';
        if ($aggregate !== 'none') {
            $reduce = function ($set) use ($aggregate, $arguments) {
                $values = $set->pluck($arguments['field'] ?? '')->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (float) $v);

                return match ($aggregate) {
                    'count' => $set->count(),
                    'sum' => round($values->sum(), 4),
                    'avg' => $values->isEmpty() ? null : round($values->avg(), 4),
                    'min' => $values->min(),
                    'max' => $values->max(),
                };
            };

            if ($group = $arguments['group_by'] ?? null) {
                return ['dataset' => $dataset->slug, 'aggregate' => $aggregate, 'by' => $group,
                    'result' => $rows->groupBy(fn ($r) => (string) ($r[$group] ?? '(empty)'))->map($reduce)->sortDesc()->take(100)];
            }

            return ['dataset' => $dataset->slug, 'aggregate' => $aggregate, 'field' => $arguments['field'] ?? null, 'result' => $reduce($rows)];
        }

        if ($sort = $arguments['sort_by'] ?? null) {
            $rows = $rows->sortBy(fn ($r) => is_numeric($r[$sort] ?? null) ? (float) $r[$sort] : ($r[$sort] ?? ''), SORT_REGULAR, (bool) ($arguments['sort_desc'] ?? false));
        }

        $limit = min((int) ($arguments['limit'] ?? 25), 200);

        return ['dataset' => $dataset->slug, 'matched' => $rows->count(), 'rows' => $rows->take($limit)->values()];
    }

    protected static function matches(mixed $actual, string $op, mixed $expected): bool
    {
        if ($op === 'contains') {
            return str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected));
        }
        if (is_numeric($actual) && is_numeric($expected)) {
            $actual = (float) $actual;
            $expected = (float) $expected;
        } else {
            $actual = mb_strtolower((string) $actual);
            $expected = mb_strtolower((string) $expected);
        }

        return match ($op) {
            '=' => $actual == $expected,
            '!=' => $actual != $expected,
            '>' => $actual > $expected,
            '<' => $actual < $expected,
            '>=' => $actual >= $expected,
            '<=' => $actual <= $expected,
            default => false,
        };
    }
}
