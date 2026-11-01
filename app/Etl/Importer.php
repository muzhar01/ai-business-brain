<?php

namespace App\Etl;

use App\Brain\Knowledge\KnowledgeBase;
use App\Models\AuditLog;
use App\Models\Dataset;
use App\Models\DatasetRow;
use App\Models\Import;
use App\Models\Lead;
use App\Models\Task;
use App\Models\Usage;
use App\Saas\Quota;
use App\Saas\QuotaExceeded;
use App\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs an import end to end: extract (SourceReader) -> map + transform -> validate -> load.
 * Modes: create (always insert), upsert (update when match_on matches, else insert),
 *        update (only update matches), skip (insert only new).
 */
class Importer
{
    protected const MAX_ERRORS = 200;

    public function __construct(protected SourceReader $reader, protected KnowledgeBase $knowledge) {}

    public function run(Import $import, bool $dryRun = false, int $dryRunLimit = 20): array
    {
        $workspace = Tenant::current();
        $import->update(['status' => 'running', 'errors' => null]);

        $stats = ['total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
        $errors = [];
        $preview = [];
        $dataset = $import->target === 'dataset' ? $this->dataset($import) : null;
        $fields = [];

        try {
            $mode = $import->option('mode', 'upsert');
            $max = $workspace?->plan()->limit('import_rows') ?? -1;
            $remaining = $max < 0 ? PHP_INT_MAX : max(0, $max - Quota::used($workspace, 'import_rows'));
            $matchOn = $import->option('match_on');

            foreach ($this->reader->rows($import->source_type, $import->source ?? [], $import->options ?? []) as $i => $raw) {
                $stats['total']++;
                $rowNumber = $i + 1;

                if (! $dryRun && $remaining <= $stats['created'] + $stats['updated']) {
                    throw new QuotaExceeded('imported rows this month', $workspace->plan()->limit('import_rows'));
                }

                [$data, $rowErrors] = $this->mapRow($import, $raw);

                if ($rowErrors) {
                    $stats['failed']++;
                    if (count($errors) < self::MAX_ERRORS) {
                        $errors[] = ['row' => $rowNumber, 'errors' => $rowErrors];
                    }

                    continue;
                }

                if ($dryRun) {
                    $preview[] = $data;
                    if (count($preview) >= $dryRunLimit) {
                        break;
                    }

                    continue;
                }

                $fields = array_unique(array_merge($fields, array_keys($data)));

                try {
                    $result = DB::transaction(fn () => $this->load($import, $data, $mode, $matchOn, $dataset));
                    $stats[$result]++;
                } catch (Throwable $e) {
                    $stats['failed']++;
                    if (count($errors) < self::MAX_ERRORS) {
                        $errors[] = ['row' => $rowNumber, 'errors' => [$e->getMessage()]];
                    }
                }

                if ($stats['total'] % 250 === 0) {
                    $this->progress($import, $stats);
                }
            }

            if ($dataset && $fields) {
                $dataset->rememberFields(array_values($fields));
                $dataset->update(['row_count' => $dataset->rows()->count()]);
            }

            $import->update([
                'status' => $dryRun ? 'draft' : 'done',
                'errors' => $errors ?: null,
                'last_run_at' => $dryRun ? $import->last_run_at : now(),
            ] + ($dryRun ? [] : $this->columns($stats)));

            if (! $dryRun && $workspace) {
                Usage::record($workspace, ['import_rows' => $stats['created'] + $stats['updated']]);
                AuditLog::record('import.completed', $import, $stats);
            }
        } catch (Throwable $e) {
            report($e);
            $import->update(['status' => 'failed', 'errors' => array_merge($errors, [['row' => null, 'errors' => [$e->getMessage()]]])] + $this->columns($stats));
            if ($dryRun) {
                throw $e;
            }
        }

        return ['stats' => $stats, 'errors' => $errors, 'preview' => $preview];
    }

    /** @return array{0: array, 1: array} mapped data and validation errors */
    public function mapRow(Import $import, array $raw): array
    {
        $data = [];
        $fields = Targets::fields($import->target);

        foreach ($import->mapping ?? [] as $m) {
            if (empty($m['target']) || (empty($m['source']) && ($m['default'] ?? '') === '')) {
                continue;
            }
            $value = Transformer::apply(Transformer::source($raw, $m['source'] ?? null), $m['transform'] ?? null);
            if (($value === null || $value === '') && ($m['default'] ?? '') !== '') {
                $value = $m['default'];
            }
            $data[$m['target']] = $value;
        }

        $errors = [];
        foreach ($fields as $name => $meta) {
            if (! empty($meta['required']) && blank($data[$name] ?? null)) {
                $errors[] = "{$name} is required";
            }
            if (isset($meta['enum'], $data[$name]) && $data[$name] !== null && ! in_array($data[$name], $meta['enum'], true)) {
                $errors[] = "{$name} must be one of: ".implode(', ', $meta['enum'])." (got \"{$data[$name]}\")";
            }
        }
        if ($import->target === 'dataset' && ! array_filter($data, fn ($v) => $v !== null && $v !== '')) {
            $errors[] = 'row is empty after mapping';
        }

        if ($fields) {
            $data = array_intersect_key($data, $fields);
        }

        return [$data, $errors];
    }

    protected function load(Import $import, array $data, string $mode, ?string $matchOn, ?Dataset $dataset): string
    {
        return match ($import->target) {
            'leads' => $this->upsertModel(Lead::class, $data, $mode, $matchOn ?: 'email'),
            'tasks' => $this->upsertModel(Task::class, $data + ['status' => $data['status'] ?? 'open'], $mode, $matchOn ?: 'title'),
            'dataset' => $this->upsertDatasetRow($dataset, $data, $mode, $matchOn),
            'knowledge' => $this->knowledgeRow($data),
        };
    }

    /** @param class-string<Model> $class */
    protected function upsertModel(string $class, array $data, string $mode, string $matchOn): string
    {
        $data = array_filter($data, fn ($v) => $v !== null);
        $existing = filled($data[$matchOn] ?? null) && $mode !== 'create'
            ? $class::where($matchOn, $data[$matchOn])->first()
            : null;

        if ($existing) {
            if ($mode === 'skip') {
                return 'skipped';
            }
            $existing->fill($data)->save();

            return 'updated';
        }

        if ($mode === 'update') {
            return 'skipped';
        }
        $class::create($data);

        return 'created';
    }

    protected function upsertDatasetRow(Dataset $dataset, array $data, string $mode, ?string $matchOn): string
    {
        $key = $matchOn ? (string) ($data[$matchOn] ?? '') : null;
        $existing = $key !== null && $key !== '' && $mode !== 'create'
            ? DatasetRow::where('dataset_id', $dataset->id)->where('external_key', $key)->first()
            : null;

        if ($existing) {
            if ($mode === 'skip') {
                return 'skipped';
            }
            $existing->update(['data' => array_merge($existing->data ?? [], $data)]);

            return 'updated';
        }
        if ($mode === 'update') {
            return 'skipped';
        }

        DatasetRow::create(['dataset_id' => $dataset->id, 'external_key' => $key ?: null, 'data' => $data]);

        return 'created';
    }

    protected function knowledgeRow(array $data): string
    {
        $this->knowledge->addText((string) $data['title'], (string) $data['content'], $data['collection'] ?? 'default', ['imported' => true]);

        return 'created';
    }

    protected function dataset(Import $import): Dataset
    {
        if ($import->dataset_id && ($d = Dataset::find($import->dataset_id))) {
            return $d;
        }
        $name = $import->option('dataset_name') ?: $import->name;
        $dataset = Dataset::firstWhere('slug', Str::slug($name, '_'));
        if (! $dataset) {
            if ($ws = Tenant::current()) {
                Quota::ensure($ws, 'datasets');
            }
            $dataset = Dataset::create(['name' => $name]);
        }
        $import->update(['dataset_id' => $dataset->id]);

        return $dataset;
    }

    protected function progress(Import $import, array $stats): void
    {
        $import->update($this->columns($stats));
    }

    protected function columns(array $stats): array
    {
        return [
            'total_rows' => $stats['total'],
            'created_rows' => $stats['created'],
            'updated_rows' => $stats['updated'],
            'skipped_rows' => $stats['skipped'],
            'failed_rows' => $stats['failed'],
        ];
    }
}
