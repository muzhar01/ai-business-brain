<?php

namespace App\Jobs;

use App\Brain\Knowledge\KnowledgeBase;
use App\Models\Document;
use App\Models\Workspace;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class IngestDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public int $documentId, public ?int $workspaceId = null) {}

    public function handle(KnowledgeBase $knowledge): void
    {
        $workspace = $this->workspaceId ? Workspace::find($this->workspaceId) : null;
        $run = function () use ($knowledge) {
            if ($document = Document::find($this->documentId)) {
                $knowledge->process($document);
            }
        };
        $workspace ? Tenant::run($workspace, $run) : $run();
    }
}
