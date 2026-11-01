<?php

namespace App\Console\Commands;

use App\Brain\Data\Message;
use App\Brain\LlmManager;
use App\Models\Workspace;
use App\Tenancy\Tenant;
use Illuminate\Console\Command;

class BrainProviders extends Command
{
    protected $signature = 'brain:providers {--workspace= : check a workspace\'s own keys} {--test : Send a one-word ping to every configured provider}';

    protected $description = 'List configured LLM providers and optionally test them';

    public function handle(LlmManager $llm): int
    {
        if ($this->option('workspace')) {
            Tenant::set(Workspace::where('id', $this->option('workspace'))->orWhere('slug', $this->option('workspace'))->firstOrFail());
        }
        $rows = [];
        foreach ($llm->available() as $p) {
            $status = '';
            if ($this->option('test')) {
                try {
                    $t = microtime(true);
                    $r = $llm->provider($p['name'])->chat([Message::user('Reply with the single word: pong')], [], ['max_tokens' => 512]);
                    $status = '✔ '.round((microtime(true) - $t) * 1000).'ms "'.mb_strimwidth(trim((string) $r->content), 0, 20, '…').'"';
                } catch (\Throwable $e) {
                    $status = '✘ '.mb_strimwidth($e->getMessage(), 0, 80, '…');
                }
            }
            $rows[] = [$p['name'].($p['default'] ? ' (default)' : ''), $p['driver'], $p['model'], $p['embeddings'] ? 'yes' : '-', $status];
        }

        if (! $rows) {
            $this->warn('No provider has an API key yet. Add one to .env, e.g. OPENAI_API_KEY=...');

            return self::FAILURE;
        }

        $this->table(['Provider', 'Driver', 'Model', 'Embeddings', 'Test'], $rows);

        return self::SUCCESS;
    }
}
