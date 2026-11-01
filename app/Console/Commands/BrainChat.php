<?php

namespace App\Console\Commands;

use App\Brain\Brain;
use App\Models\Workspace;
use App\Tenancy\Tenant;
use Illuminate\Console\Command;

use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;

class BrainChat extends Command
{
    protected $signature = 'brain:chat {workspace : workspace id or slug} {--provider= : Provider name} {--model=}';

    protected $description = 'Chat with the business brain in your terminal';

    public function handle(Brain $brain): int
    {
        $workspace = Workspace::where('id', $this->argument('workspace'))->orWhere('slug', $this->argument('workspace'))->firstOrFail();
        Tenant::set($workspace);
        $conversation = null;
        $provider = $this->option('provider');
        $this->info('AI Business Brain ('.($provider ?: config('brain.default')).'). Type "exit" to quit.');

        while (true) {
            $question = text('You');
            if (in_array(strtolower(trim($question)), ['exit', 'quit', ''], true)) {
                return self::SUCCESS;
            }

            try {
                $result = spin(fn () => $brain->ask($question, $conversation, $provider, array_filter(['model' => $this->option('model')])), 'Thinking...');
            } catch (\Throwable $e) {
                $this->error($e->getMessage());

                continue;
            }

            $conversation = $result['conversation'];
            foreach ($result['tool_runs'] as $run) {
                $this->line("<fg=gray>  ↳ {$run['tool']}</>");
            }
            $this->newLine();
            $this->line('<fg=cyan>Brain:</> '.$result['message']->content);
            if ($result['sources']) {
                $this->line('<fg=gray>Sources: '.collect($result['sources'])->pluck('title')->implode(', ').'</>');
            }
            $this->newLine();
        }
    }
}
