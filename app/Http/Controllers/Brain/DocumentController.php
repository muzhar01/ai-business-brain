<?php

namespace App\Http\Controllers\Brain;

use App\Brain\Knowledge\KnowledgeBase;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function index(Request $request)
    {
        return Document::query()
            ->when($request->query('collection'), fn ($q, $c) => $q->where('collection', $c))
            ->latest()->paginate(50);
    }

    public function store(Request $request, KnowledgeBase $knowledge)
    {
        $data = $request->validate([
            'file' => ['required_without_all:text,url', 'file', 'max:'.config('brain.knowledge.max_upload_kb'),
                'extensions:pdf,docx,pptx,xlsx,csv,tsv,txt,md,markdown,html,htm,json,xml,yaml,yml,log'],
            'text' => ['required_without_all:file,url', 'string'],
            'url' => ['required_without_all:file,text', 'url'],
            'title' => ['nullable', 'string', 'max:255'],
            'collection' => ['nullable', 'string', 'max:100', 'alpha_dash'],
        ]);

        $collection = $data['collection'] ?? 'default';

        $document = match (true) {
            $request->hasFile('file') => $knowledge->addFile($request->file('file'), $data['title'] ?? null, $collection),
            isset($data['text']) => $knowledge->addText($data['title'] ?? 'Note '.now()->toDateString(), $data['text'], $collection),
            default => $knowledge->addUrl($data['url'], $collection),
        };

        AuditLog::record('document.added', $document, ['title' => $document->title]);

        return response()->json($document, 201);
    }

    public function show(Document $document)
    {
        return $document->load(['chunks' => fn ($q) => $q->orderBy('position')->select(['id', 'document_id', 'position', 'content'])]);
    }

    public function reprocess(Document $document, KnowledgeBase $knowledge)
    {
        return $knowledge->process($document);
    }

    public function destroy(Document $document)
    {
        if ($document->source_type === 'file' && $document->path) {
            Storage::disk(config('brain.knowledge.disk'))->delete($document->path);
        }
        AuditLog::record('document.deleted', $document, ['title' => $document->title]);
        $document->delete();

        return response()->noContent();
    }

    public function search(Request $request, KnowledgeBase $knowledge)
    {
        $data = $request->validate(['q' => 'required|string|max:2000', 'limit' => 'nullable|integer|min:1|max:50', 'collection' => 'nullable|string']);

        return $knowledge->search($data['q'], $data['limit'] ?? null, $data['collection'] ?? null);
    }
}
