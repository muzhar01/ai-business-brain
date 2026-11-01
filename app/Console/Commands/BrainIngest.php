<?php

namespace App\Console\Commands;

use App\Brain\Knowledge\KnowledgeBase;
use App\Models\Workspace;
use App\Tenancy\Tenant;
use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

class BrainIngest extends Command
{
    protected $signature = 'brain:ingest {workspace : workspace id or slug} {path : File, folder or URL} {--collection=default} {--sync : Process now instead of queueing}';

    protected $description = 'Teach the brain: ingest a file, a whole folder, or a web page';

    public function handle(KnowledgeBase $knowledge): int
    {
        Tenant::set(Workspace::where('id', $this->argument('workspace'))->orWhere('slug', $this->argument('workspace'))->firstOrFail());

        if ($this->option('sync')) {
            config(['brain.knowledge.queue' => false]);
        }

        $path = $this->argument('path');
        $collection = $this->option('collection');

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            $doc = $knowledge->addUrl($path, $collection);
            $this->printDocument($doc);

            return self::SUCCESS;
        }

        $files = is_dir($path)
            ? iterator_to_array((new Finder)->files()->in($path)->name('/\.(pdf|docx|pptx|xlsx|csv|tsv|txt|md|markdown|html?|json|xml|ya?ml)$/i'))
            : [new \SplFileInfo($path)];

        foreach ($files as $file) {
            $this->printDocument($knowledge->addFile($file->getRealPath() ?: $file->getPathname(), $file->getFilename(), $collection));
        }

        return self::SUCCESS;
    }

    protected function printDocument($doc): void
    {
        $line = "#{$doc->id} {$doc->title} -> {$doc->status}".($doc->chunk_count ? " ({$doc->chunk_count} chunks)" : '');
        $doc->status === 'failed' ? $this->error($line.': '.$doc->error) : $this->info($line);
    }
}
