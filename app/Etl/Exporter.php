<?php

namespace App\Etl;

use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Dataset;
use App\Models\DatasetRow;
use App\Models\Document;
use App\Models\Export;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Task;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Exports any workspace data to CSV, JSON or XLSX with column mapping (source -> header) and filters. */
class Exporter
{
    public static function targets(): array
    {
        return [
            'leads' => ['label' => 'Leads', 'fields' => ['id', 'name', 'email', 'phone', 'company', 'status', 'value', 'source', 'notes', 'created_at', 'updated_at']],
            'tasks' => ['label' => 'Tasks', 'fields' => ['id', 'title', 'description', 'due_date', 'priority', 'status', 'assignee', 'lead_id', 'created_at']],
            'messages' => ['label' => 'Conversations (messages)', 'fields' => ['conversation_id', 'conversation_title', 'channel', 'role', 'content', 'provider', 'model', 'input_tokens', 'output_tokens', 'rating', 'created_at']],
            'documents' => ['label' => 'Knowledge documents', 'fields' => ['id', 'title', 'collection', 'source_type', 'status', 'chunk_count', 'created_at']],
            'dataset' => ['label' => 'Dataset rows', 'fields' => []],
            'audit' => ['label' => 'Audit log', 'fields' => ['created_at', 'action', 'user_id', 'subject_type', 'subject_id', 'ip', 'meta']],
        ];
    }

    public function run(Export $export): Export
    {
        $export->update(['status' => 'running', 'error' => null]);

        try {
            $fields = $export->fields ?: array_map(fn ($f) => ['source' => $f, 'header' => $f], $this->defaultFields($export));
            $headers = array_column($fields, 'header');
            $rows = (function () use ($export, $fields) {
                foreach ($this->records($export) as $record) {
                    $line = [];
                    foreach ($fields as $f) {
                        $value = Transformer::apply(data_get($record, $f['source']), $f['transform'] ?? null);
                        $line[] = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
                    }
                    yield $line;
                }
            })();

            $disk = Storage::disk(config('brain.knowledge.disk'));
            $path = "exports/{$export->workspace_id}/{$export->target}-{$export->id}-".now()->format('Ymd-His').".{$export->format}";
            $disk->makeDirectory(dirname($path));
            $absolute = $disk->path($path);
            $count = 0;
            $counting = (function () use ($rows, &$count) {
                foreach ($rows as $r) {
                    $count++;
                    yield $r;
                }
            })();

            match ($export->format) {
                'xlsx' => XlsxWriter::write($absolute, $headers, $counting),
                'json' => $this->json($absolute, $headers, $counting),
                default => $this->csv($absolute, $headers, $counting),
            };

            $export->update(['status' => 'done', 'path' => $path, 'rows' => $count]);
            AuditLog::record('export.completed', $export, ['rows' => $count, 'format' => $export->format]);
        } catch (Throwable $e) {
            report($e);
            $export->update(['status' => 'failed', 'error' => $e->getMessage()]);
        }

        return $export->fresh();
    }

    protected function defaultFields(Export $export): array
    {
        if ($export->target === 'dataset') {
            return array_merge(['id'], array_map(fn ($f) => 'data.'.$f, Dataset::find($export->dataset_id)?->fieldNames() ?? []));
        }

        return self::targets()[$export->target]['fields'];
    }

    protected function records(Export $export): \Generator
    {
        $f = $export->filters ?? [];
        $dates = function ($q, $column = 'created_at') use ($f) {
            return $q->when($f['from'] ?? null, fn ($q, $d) => $q->whereDate($column, '>=', $d))
                ->when($f['to'] ?? null, fn ($q, $d) => $q->whereDate($column, '<=', $d));
        };

        $query = match ($export->target) {
            'leads' => $dates(Lead::query())->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s)),
            'tasks' => $dates(Task::query())->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s)),
            'documents' => $dates(Document::query())->when($f['collection'] ?? null, fn ($q, $c) => $q->where('collection', $c)),
            'dataset' => DatasetRow::query()->where('dataset_id', $export->dataset_id),
            'audit' => $dates(AuditLog::query()->where('workspace_id', $export->workspace_id)),
            'messages' => $dates(Message::query()->whereIn('role', ['user', 'assistant'])->whereNotNull('content')),
        };

        $titles = [];
        foreach ($query->orderBy('id')->lazyById(500) as $model) {
            $row = $model->toArray();
            if ($export->target === 'messages') {
                $c = $titles[$model->conversation_id] ??= Conversation::find($model->conversation_id);
                $row['conversation_id'] = $c?->uuid;
                $row['conversation_title'] = $c?->title;
                $row['channel'] = $c?->channel;
            }
            if ($export->target === 'dataset') {
                $row['data'] = $model->data;
            }
            yield $row;
        }
    }

    protected function csv(string $path, array $headers, iterable $rows): void
    {
        $fh = fopen($path, 'w');
        fwrite($fh, "\xEF\xBB\xBF"); // Excel-friendly UTF-8
        fputcsv($fh, $headers, ',', '"', '\\');
        foreach ($rows as $row) {
            // neutralise spreadsheet formula injection
            fputcsv($fh, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, $row), ',', '"', '\\');
        }
        fclose($fh);
    }

    protected function json(string $path, array $headers, iterable $rows): void
    {
        $fh = fopen($path, 'w');
        fwrite($fh, '[');
        $first = true;
        foreach ($rows as $row) {
            fwrite($fh, ($first ? '' : ',')."\n".json_encode(array_combine($headers, $row), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $first = false;
        }
        fwrite($fh, "\n]");
        fclose($fh);
    }
}
