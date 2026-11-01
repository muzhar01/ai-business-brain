<?php

namespace App\Brain\Knowledge;

use App\Brain\Contracts\VectorStore;
use App\Brain\LlmManager;
use App\Jobs\IngestDocument;
use App\Models\Chunk;
use App\Models\Document;
use App\Saas\Quota;
use App\Security\UrlGuard;
use App\Tenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class KnowledgeBase
{
    protected const STOPWORDS = ['the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'any', 'can', 'had', 'her', 'was', 'one', 'our', 'out', 'has', 'have', 'what', 'when', 'where', 'which', 'who', 'why', 'how', 'does', 'did', 'this', 'that', 'with', 'from', 'your', 'about', 'into', 'there', 'their', 'them', 'they', 'will', 'would', 'should', 'could', 'tell', 'please', 'give', 'show'];

    public function __construct(
        protected LlmManager $llm,
        protected VectorStore $store,
        protected TextExtractor $extractor,
    ) {}

    public function addText(string $title, string $text, string $collection = 'default', array $meta = []): Document
    {
        $this->ensureCapacity();
        $document = Document::create([
            'title' => $title,
            'collection' => $collection,
            'source_type' => 'text',
            'hash' => hash('sha256', $text),
            'meta' => $meta + ['text' => $text],
        ]);

        return $this->queue($document);
    }

    public function addFile(UploadedFile|string $file, ?string $title = null, string $collection = 'default'): Document
    {
        $this->ensureCapacity();
        $disk = config('brain.knowledge.disk');

        if ($file instanceof UploadedFile) {
            $path = $file->store('brain/documents', $disk);
            $title ??= $file->getClientOriginalName();
            $mime = $file->getMimeType();
            $ext = $file->getClientOriginalExtension();
        } else {
            $path = 'brain/documents/'.Str::ulid().'.'.pathinfo($file, PATHINFO_EXTENSION);
            Storage::disk($disk)->put($path, file_get_contents($file));
            $title ??= basename($file);
            $mime = mime_content_type($file) ?: null;
            $ext = pathinfo($file, PATHINFO_EXTENSION);
        }

        $document = Document::create([
            'title' => $title,
            'collection' => $collection,
            'source_type' => 'file',
            'path' => $path,
            'mime' => $mime,
            'hash' => hash_file('sha256', Storage::disk($disk)->path($path)),
            'meta' => ['extension' => strtolower($ext)],
        ]);

        return $this->queue($document);
    }

    public function addUrl(string $url, string $collection = 'default'): Document
    {
        $this->ensureCapacity();
        $document = Document::create([
            'title' => $url,
            'collection' => $collection,
            'source_type' => 'url',
            'path' => $url,
        ]);

        return $this->queue($document);
    }

    protected function ensureCapacity(): void
    {
        if ($workspace = Tenant::current()) {
            Quota::ensure($workspace, 'documents');
        }
    }

    protected function queue(Document $document): Document
    {
        config('brain.knowledge.queue')
            ? IngestDocument::dispatch($document->id, $document->workspace_id)
            : $this->process($document);

        return $document->fresh();
    }

    /** Extract -> chunk -> embed -> store. Idempotent: re-processing replaces chunks. */
    public function process(Document $document): Document
    {
        $document->update(['status' => 'processing', 'error' => null]);

        try {
            $text = $this->textFor($document);
            $chunker = new Chunker(config('brain.knowledge.chunk_size'), config('brain.knowledge.chunk_overlap'));
            $pieces = $chunker->split($text);

            DB::transaction(function () use ($document, $pieces) {
                $document->chunks()->delete();
                foreach ($pieces as $i => $piece) {
                    $document->chunks()->create([
                        'collection' => $document->collection,
                        'position' => $i,
                        'content' => $piece,
                    ]);
                }
            });

            $warning = null;
            if ($embedder = $this->llm->embedder()) {
                try {
                    $document->chunks()->orderBy('position')->get()
                        ->chunk((int) config('brain.embeddings.batch_size', 64))
                        ->each(function (Collection $batch) use ($embedder) {
                            $vectors = $embedder->embed($batch->map(fn (Chunk $c) => $c->content)->values()->all());
                            foreach ($batch->values() as $i => $chunk) {
                                if (isset($vectors[$i])) {
                                    $this->store->store($chunk, $vectors[$i]);
                                }
                            }
                        });
                } catch (Throwable $e) {
                    report($e);
                    // Chunks stay searchable by keyword; reprocess later to add vectors.
                    $warning = 'Embeddings skipped (keyword search only): '.Str::limit($e->getMessage(), 500);
                }
            }

            $document->update(['status' => 'ready', 'chunk_count' => count($pieces), 'error' => $warning]);
        } catch (Throwable $e) {
            report($e);
            $document->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 1000)]);
        }

        return $document->fresh();
    }

    protected function textFor(Document $document): string
    {
        return match ($document->source_type) {
            'text' => (string) ($document->meta['text'] ?? ''),
            'url' => $this->extractor->clean($this->extractor->html(
                UrlGuard::http($document->path)->timeout(30)->get($document->path)->throw()->body()
            )),
            default => $this->extractor->extract(
                Storage::disk(config('brain.knowledge.disk'))->path($document->path),
                $document->meta['extension'] ?? null,
            ),
        };
    }

    /**
     * @return Collection<int, array{content: string, score: float, document_id: int, title: string, position: int}>
     */
    public function search(string $query, ?int $limit = null, ?string $collection = null): Collection
    {
        $limit ??= (int) config('brain.knowledge.top_k', 5);
        $results = collect();

        if ($embedder = $this->llm->embedder()) {
            try {
                $vector = $embedder->embed([$query])[0] ?? null;
                if ($vector) {
                    $results = $this->store->search($vector, $limit, $collection)
                        ->filter(fn ($r) => $r['score'] >= (float) config('brain.knowledge.min_score', 0.25));
                }
            } catch (Throwable $e) {
                report($e); // fall back to keywords below
            }
        }

        if ($results->isEmpty()) {
            $results = $this->keywordSearch($query, $limit, $collection);
        }

        return $results->map(fn ($r) => [
            'content' => $r['chunk']->content,
            'score' => $r['score'],
            'document_id' => $r['chunk']->document_id,
            'title' => $r['chunk']->document->title ?? 'Untitled',
            'position' => $r['chunk']->position,
        ])->values();
    }

    public function keywordSearch(string $query, int $limit, ?string $collection = null): Collection
    {
        $terms = collect(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn ($t) => mb_strlen($t) > 2 && ! in_array($t, self::STOPWORDS, true))
            ->unique()->take(10)->values();

        if ($terms->isEmpty()) {
            return collect();
        }

        $candidates = Chunk::with('document')
            ->when($collection, fn ($q) => $q->where('collection', $collection))
            ->where(function ($q) use ($terms) {
                if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                    $q->whereFullText('content', $terms->implode(' '));
                } else {
                    foreach ($terms as $term) {
                        $q->orWhereRaw('LOWER(content) LIKE ?', ['%'.$term.'%']);
                    }
                }
            })
            ->limit(400)
            ->get();

        return $candidates->map(function (Chunk $chunk) use ($terms) {
            $haystack = mb_strtolower($chunk->content);
            $hits = $terms->sum(fn ($t) => min(5, mb_substr_count($haystack, $t)));
            $coverage = $terms->filter(fn ($t) => str_contains($haystack, $t))->count() / $terms->count();

            return ['chunk' => $chunk, 'score' => round($coverage * 0.8 + min(1, $hits / 10) * 0.2, 4)];
        })->sortByDesc('score')->take($limit)->values();
    }
}
