<?php

namespace App\Brain\VectorStores;

use App\Brain\Contracts\VectorStore;
use App\Models\Chunk;
use App\Tenancy\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Native Postgres + pgvector with an HNSW cosine index. Millions of chunks. */
class PgVectorStore implements VectorStore
{
    public function store(Chunk $chunk, array $embedding): void
    {
        DB::update('UPDATE brain_chunks SET embedding_vec = ?::vector WHERE id = ?', [self::literal($embedding), $chunk->id]);
    }

    public function search(array $embedding, int $limit, ?string $collection = null): Collection
    {
        $rows = DB::table('brain_chunks')
            ->select('id')
            ->selectRaw('1 - (embedding_vec <=> ?::vector) AS score', [self::literal($embedding)])
            ->whereNotNull('embedding_vec')
            ->when(Tenant::id(), fn ($q, $id) => $q->where('workspace_id', $id))
            ->when($collection, fn ($q) => $q->where('collection', $collection))
            ->orderByRaw('embedding_vec <=> ?::vector', [self::literal($embedding)])
            ->limit($limit)
            ->get();

        $chunks = Chunk::with('document')->whereIn('id', $rows->pluck('id'))->get()->keyBy('id');

        return $rows->map(fn ($r) => ['chunk' => $chunks[$r->id] ?? null, 'score' => round((float) $r->score, 4)])
            ->filter(fn ($r) => $r['chunk'] !== null)
            ->values();
    }

    protected static function literal(array $v): string
    {
        return '['.implode(',', array_map(fn ($x) => (float) $x, $v)).']';
    }
}
