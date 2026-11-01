<?php

namespace App\Etl;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Easy migration: one portable .zip per workspace (all tables as JSON + uploaded files).
 * Restore it on any server / any database (MySQL <-> Postgres <-> SQLite); ids are remapped.
 */
class WorkspaceBackup
{
    public const VERSION = 2;

    /** table => [foreign key column => referenced table] (order matters: parents first) */
    protected const TABLES = [
        'brain_agents' => [],
        'brain_leads' => [],
        'brain_tasks' => ['lead_id' => 'brain_leads'],
        'brain_documents' => [],
        'brain_chunks' => ['document_id' => 'brain_documents'],
        'brain_conversations' => ['agent_id' => 'brain_agents', 'user_id' => null],
        'brain_messages' => ['conversation_id' => 'brain_conversations'],
        'brain_pending_actions' => ['conversation_id' => 'brain_conversations'],
        'brain_datasets' => [],
        'brain_dataset_rows' => ['dataset_id' => 'brain_datasets'],
    ];

    public function export(Workspace $workspace, ?string $target = null): string
    {
        $target ??= storage_path('app/backups/'.Str::slug($workspace->name).'-'.now()->format('Ymd-His').'.zip');
        @mkdir(dirname($target), 0775, true);

        $zip = new ZipArchive;
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot write {$target}");
        }

        $zip->addFromString('manifest.json', json_encode([
            'version' => self::VERSION,
            'app' => config('app.name'),
            'exported_at' => now()->toIso8601String(),
            'workspace' => [
                'name' => $workspace->name,
                'settings' => $workspace->settings,
                'provider_keys' => null, // secrets are never exported
            ],
            'counts' => collect(self::TABLES)->keys()->mapWithKeys(fn ($t) => [$t => DB::table($t)->where('workspace_id', $workspace->id)->count()]),
        ], JSON_PRETTY_PRINT));

        foreach (array_keys(self::TABLES) as $table) {
            $tmp = tempnam(sys_get_temp_dir(), 'bk');
            $fh = fopen($tmp, 'w');
            DB::table($table)->where('workspace_id', $workspace->id)->orderBy('id')
                ->lazyById(1000)->each(fn ($row) => fwrite($fh, json_encode($row, JSON_UNESCAPED_UNICODE)."\n"));
            fclose($fh);
            $zip->addFile($tmp, "data/{$table}.ndjson");
            $cleanup[] = $tmp;
        }

        $disk = Storage::disk(config('brain.knowledge.disk'));
        DB::table('brain_documents')->where('workspace_id', $workspace->id)->whereNotNull('path')->where('source_type', 'file')
            ->pluck('path')->each(function ($path) use ($zip, $disk) {
                if ($disk->exists($path)) {
                    $zip->addFile($disk->path($path), 'files/'.$path);
                }
            });

        $zip->close();
        foreach ($cleanup ?? [] as $tmp) {
            @unlink($tmp);
        }

        return $target;
    }

    /** Restores into a NEW workspace owned by $owner (or into $into if given and empty). */
    public function import(string $zipPath, ?User $owner = null, ?Workspace $into = null): Workspace
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Not a valid backup file.');
        }
        $manifest = json_decode($zip->getFromName('manifest.json') ?: 'null', true);
        if (! $manifest || ($manifest['version'] ?? 0) > self::VERSION) {
            throw new RuntimeException('Unsupported backup version.');
        }

        return DB::transaction(function () use ($zip, $manifest, $owner, $into) {
            $workspace = $into ?? ($owner
                ? Workspace::createFor($owner, $manifest['workspace']['name'].' (restored)')
                : Workspace::create(['name' => $manifest['workspace']['name'].' (restored)']));
            $workspace->updateSettings($manifest['workspace']['settings'] ?? []);

            if ($into === null) {
                // drop the auto-created default agent; the backup brings its own
                DB::table('brain_agents')->where('workspace_id', $workspace->id)->delete();
            }

            $map = [];
            $disk = Storage::disk(config('brain.knowledge.disk'));

            foreach (self::TABLES as $table => $foreign) {
                $map[$table] = [];
                $columns = array_flip(Schema::getColumnListing($table));
                $data = $zip->getFromName("data/{$table}.ndjson");
                if ($data === false) {
                    continue;
                }
                foreach (preg_split("/\n/", $data, -1, PREG_SPLIT_NO_EMPTY) as $line) {
                    $row = json_decode($line, true);
                    $oldId = $row['id'];
                    unset($row['id']);
                    $row['workspace_id'] = $workspace->id;

                    foreach ($foreign as $column => $parent) {
                        if (! array_key_exists($column, $row) || $row[$column] === null) {
                            continue;
                        }
                        $row[$column] = $parent ? ($map[$parent][$row[$column]] ?? null) : null;
                    }
                    if ($table === 'brain_conversations') {
                        $row['uuid'] = (string) Str::ulid();
                    }
                    if ($table === 'brain_documents' && ! empty($row['path']) && $row['source_type'] === 'file') {
                        $contents = $zip->getFromName('files/'.$row['path']);
                        $newPath = 'brain/documents/'.Str::ulid().'.'.pathinfo($row['path'], PATHINFO_EXTENSION);
                        if ($contents !== false) {
                            $disk->put($newPath, $contents);
                        }
                        $row['path'] = $newPath;
                    }
                    // skip orphans whose parent didn't survive
                    if (in_array($table, ['brain_chunks', 'brain_messages', 'brain_dataset_rows'], true) && reset($foreign) && $row[array_key_first($foreign)] === null) {
                        continue;
                    }

                    // tolerate schema differences between servers (e.g. pgvector column)
                    $map[$table][$oldId] = DB::table($table)->insertGetId(array_intersect_key($row, $columns));
                }
            }

            $zip->close();

            return $workspace;
        });
    }
}
