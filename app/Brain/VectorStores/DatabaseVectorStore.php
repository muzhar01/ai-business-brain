<?php

namespace App\Brain\VectorStores;

use App\Brain\Contracts\VectorStore;
use App\Models\Chunk;
use Illuminate\Support\Collection;
use SplPriorityQueue;

/**
 * Portable store: embeddings as JSON, cosine similarity in PHP.
 * Works on MySQL, MariaDB, SQLite, Postgres and SQL Server.
 * Comfortable up to ~50k chunks; switch to pgvector beyond that.
 */
class DatabaseVectorStore implements VectorStore
{
    public function store(Chunk $chunk, array $embedding): void
    {
        $chunk->forceFill(['embedding' => json_encode(self::normalize($embedding))])->save();
    }

    public function search(array $embedding, int $limit, ?string $collection = null): Collection
    {
        $query = self::normalize($embedding);
        $heap = new SplPriorityQueue; // min-heap via negated scores
        $heap->setExtractFlags(SplPriorityQueue::EXTR_BOTH);

        Chunk::query()
            ->select(['id', 'embedding'])
            ->whereNotNull('embedding')
            ->when($collection, fn ($q) => $q->where('collection', $collection))
            ->chunkById(500, function ($rows) use ($query, $heap, $limit) {
                foreach ($rows as $row) {
                    $vector = json_decode($row->embedding, true);
                    if (! is_array($vector) || count($vector) !== count($query)) {
                        continue;
                    }
                    $score = 0.0;
                    foreach ($query as $i => $q) {
                        $score += $q * $vector[$i];
                    }
                    $heap->insert($row->id, -$score);
                    if ($heap->count() > $limit) {
                        $heap->extract();
                    }
                }
            });

        $scores = [];
        while (! $heap->isEmpty()) {
            $item = $heap->extract();
            $scores[$item['data']] = -$item['priority'];
        }

        $chunks = Chunk::with('document')->whereIn('id', array_keys($scores))->get()->keyBy('id');

        return collect($scores)
            ->sortDesc()
            ->map(fn ($score, $id) => ['chunk' => $chunks[$id], 'score' => round($score, 4)])
            ->filter(fn ($r) => $r['chunk'] !== null)
            ->values();
    }

    public static function normalize(array $v): array
    {
        $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $v))) ?: 1.0;

        return array_map(fn ($x) => $x / $norm, $v);
    }
}
