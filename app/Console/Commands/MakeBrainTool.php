<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeBrainTool extends Command
{
    protected $signature = 'make:brain-tool {name : e.g. CheckStock}';

    protected $description = 'Scaffold a new tool (action) the brain can call';

    public function handle(): int
    {
        $class = Str::studly(Str::finish($this->argument('name'), 'Tool'));
        $path = app_path("Brain/Tools/{$class}.php");

        if (file_exists($path)) {
            $this->error("{$class} already exists.");

            return self::FAILURE;
        }

        $toolName = Str::snake(Str::beforeLast($class, 'Tool'));

        file_put_contents($path, <<<PHP
<?php

namespace App\Brain\Tools;

use App\Brain\Contracts\Tool;
use App\Models\Conversation;

class {$class} implements Tool
{
    public function name(): string
    {
        return '{$toolName}';
    }

    public function description(): string
    {
        return 'Describe what this does and when the brain should use it.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'example' => ['type' => 'string', 'description' => 'An argument'],
            ],
            'required' => ['example'],
        ];
    }

    public function requiresApproval(): bool
    {
        return false; // true for anything that sends, pays or deletes
    }

    public function handle(array \$arguments, ?Conversation \$conversation = null): string|array
    {
        return ['ok' => true, 'echo' => \$arguments['example']];
    }
}

PHP);

        $this->info("Created app/Brain/Tools/{$class}.php");
        $this->line("Now add App\\Brain\\Tools\\{$class}::class to 'tools.enabled' in config/brain.php.");

        return self::SUCCESS;
    }
}
