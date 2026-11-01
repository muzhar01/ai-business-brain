<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Upgrades the single-tenant brain to multi-tenant SaaS.
 * Safe on existing installs: old rows are moved into a "Default" workspace.
 */
return new class extends Migration
{
    protected array $tenantTables = ['brain_conversations', 'brain_messages', 'brain_documents', 'brain_chunks', 'brain_leads', 'brain_tasks', 'brain_pending_actions'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
            $table->unsignedBigInteger('current_workspace_id')->nullable();
        });

        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('plan_override')->nullable();
            $table->json('settings')->nullable();
            $table->text('provider_keys')->nullable();      // encrypted JSON (BYOK)
            $table->string('widget_key', 40)->unique();
            $table->text('telegram_bot_token')->nullable(); // encrypted
            $table->string('telegram_secret', 64)->nullable();
            $table->boolean('suspended')->default(false);
            // Laravel Cashier (Stripe) customer columns
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('workspace_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16)->default('member'); // owner|admin|member
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id']);
        });

        Schema::create('workspace_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 16)->default('member');
            $table->string('token', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id');
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'stripe_status']);
        });

        Schema::create('subscription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id');
            $table->string('stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->string('meter_id')->nullable();
            $table->integer('quantity')->nullable();
            $table->string('meter_event_name')->nullable();
            $table->timestamps();
            $table->index(['subscription_id', 'stripe_price']);
        });

        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->string('prefix', 12);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('brain_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->text('instructions')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->decimal('temperature', 3, 2)->nullable();
            $table->json('tools')->nullable(); // null = all allowed
            $table->string('collection')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('widget_enabled')->default(false);
            $table->string('widget_greeting')->nullable();
            $table->string('widget_color', 9)->nullable();
            $table->timestamps();
        });

        Schema::create('brain_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->char('period', 7); // YYYY-MM
            $table->unsignedInteger('messages')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedInteger('import_rows')->default(0);
            $table->timestamps();
            $table->unique(['workspace_id', 'period']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['workspace_id', 'created_at']);
        });

        Schema::create('brain_datasets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->json('fields')->nullable(); // [{name, type, label}]
            $table->unsignedInteger('row_count')->default(0);
            $table->timestamps();
            $table->unique(['workspace_id', 'slug']);
        });

        Schema::create('brain_dataset_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dataset_id')->constrained('brain_datasets')->cascadeOnDelete();
            $table->string('external_key')->nullable();
            $table->json('data');
            $table->timestamps();
            $table->index(['dataset_id', 'external_key']);
        });

        Schema::create('brain_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('target', 24);                    // leads|tasks|dataset|knowledge
            $table->foreignId('dataset_id')->nullable()->constrained('brain_datasets')->nullOnDelete();
            $table->string('source_type', 16)->default('upload'); // upload|url|database
            $table->text('source')->nullable();               // encrypted JSON
            $table->json('columns')->nullable();              // detected source columns
            $table->json('mapping')->nullable();              // [{source, target, transform, default}]
            $table->json('options')->nullable();              // mode, match_on, delimiter, sheet, json_path
            $table->string('status', 16)->default('draft');   // draft|queued|running|done|failed
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('created_rows')->default(0);
            $table->unsignedInteger('updated_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->json('errors')->nullable();
            $table->string('schedule', 16)->nullable();       // hourly|daily|weekly
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        Schema::create('brain_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target', 24);
            $table->foreignId('dataset_id')->nullable()->constrained('brain_datasets')->nullOnDelete();
            $table->string('format', 8)->default('csv');      // csv|json|xlsx
            $table->json('fields')->nullable();               // [{source, header}]
            $table->json('filters')->nullable();
            $table->string('status', 16)->default('queued');
            $table->string('path')->nullable();
            $table->unsignedInteger('rows')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });

        foreach ($this->tenantTables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('workspace_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            });
        }

        Schema::table('brain_conversations', function (Blueprint $table) {
            $table->foreignId('agent_id')->nullable()->constrained('brain_agents')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 16)->default('app'); // app|api|widget|telegram
            $table->string('external_ref')->nullable()->index();
        });

        Schema::table('brain_messages', function (Blueprint $table) {
            $table->tinyInteger('rating')->nullable(); // -1 | 1
        });

        $this->backfill();
    }

    /** Existing single-tenant data -> "Default" workspace. */
    protected function backfill(): void
    {
        $hasData = collect($this->tenantTables)->contains(fn ($t) => DB::table($t)->exists());
        if (! $hasData) {
            return;
        }

        $owner = DB::table('users')->orderBy('id')->first();
        $id = DB::table('workspaces')->insertGetId([
            'uuid' => (string) Str::ulid(),
            'name' => config('brain.business.name', 'Default'),
            'slug' => 'default-'.Str::lower(Str::random(5)),
            'owner_id' => $owner?->id,
            'widget_key' => 'wk_'.Str::random(32),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($owner) {
            DB::table('workspace_user')->insert(['workspace_id' => $id, 'user_id' => $owner->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('users')->where('id', $owner->id)->update(['current_workspace_id' => $id, 'is_admin' => true]);
        }

        foreach ($this->tenantTables as $t) {
            DB::table($t)->whereNull('workspace_id')->update(['workspace_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('brain_conversations', fn (Blueprint $table) => $table->dropIndex(['external_ref']));
        foreach (['brain_messages' => ['rating'], 'brain_conversations' => ['external_ref', 'channel']] as $t => $cols) {
            Schema::table($t, fn (Blueprint $table) => $table->dropColumn($cols));
        }
        Schema::table('brain_conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agent_id');
            $table->dropConstrainedForeignId('user_id');
        });
        foreach ($this->tenantTables as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropConstrainedForeignId('workspace_id'));
        }
        foreach (['brain_exports', 'brain_imports', 'brain_dataset_rows', 'brain_datasets', 'audit_logs', 'brain_usage', 'brain_agents', 'api_tokens', 'subscription_items', 'subscriptions', 'workspace_invitations', 'workspace_user', 'workspaces'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['is_admin', 'current_workspace_id']));
    }
};
