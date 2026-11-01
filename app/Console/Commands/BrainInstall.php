<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class BrainInstall extends Command
{
    protected $signature = 'brain:install {--email=} {--password=} {--name=} {--workspace=}';

    protected $description = 'One-command setup: migrate, create the super admin and first workspace';

    public function handle(): int
    {
        if (blank(config('app.key'))) {
            $this->call('key:generate', ['--force' => true]);
        }
        $this->call('migrate', ['--force' => true]);
        $this->call('storage:link');

        $email = $this->option('email') ?: text('Admin email', required: true);
        $user = User::firstOrNew(['email' => $email]);
        if (! $user->exists) {
            $user->name = $this->option('name') ?: text('Your name', default: 'Admin');
            $user->password = Hash::make($this->option('password') ?: password('Password', required: true));
        }
        $user->is_admin = true;
        $user->save();

        if (! $user->workspaces()->exists()) {
            Workspace::createFor($user, $this->option('workspace') ?: text('Company / workspace name', default: config('app.name')));
        }

        $this->info('Ready. Sign in at '.url('/login')." as {$email} (super admin).");
        $this->line('Next: add an LLM key to .env (or per workspace in Settings), run `php artisan queue:work` and the scheduler.');

        return self::SUCCESS;
    }
}
