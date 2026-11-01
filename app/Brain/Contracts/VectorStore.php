<?php

namespace App\Brain\Contracts;

use App\Models\Chunk;
use Illuminate\Support\Collection;

interface VectorStore
{
    /** @param array<int, float> $embedding */
    public function store(Chunk $chunk, array $embedding): void;

    /**
     * @param  array<int, float>  $embedding
     * @return Collection<int, array{chunk: Chunk, score: float}>
     */
    public function search(array $embedding, int $limit, ?string $collection = null): Collection;
}
