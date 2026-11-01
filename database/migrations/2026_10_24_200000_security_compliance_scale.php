<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** v3: 2FA, auth events, tamper-evident audit, outbound webhooks, WhatsApp, privacy, scale indexes. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
        });

        Schema::create('auth_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('email')->nullable();
            $table->string('event', 32); // login|failed|logout|2fa_failed|2fa_enabled|2fa_disabled|password_changed|locked
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['user_id', 'created_at']);
            $table->index(['email', 'created_at']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->char('prev_hash', 64)->nullable();
            $table->char('hash', 64)->nullable();
        });

        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('url', 500);
            $table->text('secret'); // encrypted
            $table->json('events');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('failures')->default(0);
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('event', 64);
            $table->ulid('event_id');
            $table->json('payload');
            $table->string('status', 16)->default('pending'); // pending|delivered|failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamps();
            $table->index(['webhook_endpoint_id', 'created_at']);
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->text('whatsapp')->nullable(); // encrypted JSON: phone_number_id, token, verify_token, app_secret
        });

        // Scale: composite indexes for every hot tenant query
        Schema::table('brain_messages', fn (Blueprint $t) => $t->index(['workspace_id', 'role', 'created_at']));
        Schema::table('brain_conversations', function (Blueprint $t) {
            $t->index(['workspace_id', 'updated_at']);
            $t->index(['workspace_id', 'channel', 'external_ref']);
        });
        Schema::table('brain_leads', function (Blueprint $t) {
            $t->index(['workspace_id', 'status']);
            $t->index(['workspace_id', 'email']);
        });
        Schema::table('brain_tasks', fn (Blueprint $t) => $t->index(['workspace_id', 'status', 'due_date']));
        Schema::table('brain_documents', fn (Blueprint $t) => $t->index(['workspace_id', 'collection']));
        Schema::table('brain_chunks', fn (Blueprint $t) => $t->index(['workspace_id', 'document_id']));
        Schema::table('brain_pending_actions', fn (Blueprint $t) => $t->index(['workspace_id', 'status']));
        Schema::table('brain_dataset_rows', fn (Blueprint $t) => $t->index(['workspace_id', 'dataset_id', 'external_key']));
        Schema::table('brain_imports', fn (Blueprint $t) => $t->index(['schedule', 'status']));
    }

    public function down(): void
    {
        Schema::table('brain_imports', fn (Blueprint $t) => $t->dropIndex(['schedule', 'status']));
        Schema::table('brain_dataset_rows', fn (Blueprint $t) => $t->dropIndex(['workspace_id', 'dataset_id', 'external_key']));
        Schema::table('brain_pending_actions', fn (Blueprint $t) => $t->dropIndex(['workspace_id', 'status']));
        Schema::table('brain_chunks', fn (Blueprint $t) => $t->dropIndex(['workspace_id', 'document_id']));
        Schema::table('brain_documents', fn (Blueprint $t) => $t->dropIndex(['workspace_id', 'collection']));
        Schema::table('brain_tasks', fn (Blueprint $t) => $t->dropIndex(['workspace_id', 'status', 'due_date']));
        Schema::table('brain_leads', function (Blueprint $t) {
            $t->dropIndex(['workspace_id', 'status']);
            $t->dropIndex(['workspace_id', 'email']);
        });
        Schema::table('brain_conversations', function (Blueprint $t) {
            $t->dropIndex(['workspace_id', 'updated_at']);
            $t->dropIndex(['workspace_id', 'channel', 'external_ref']);
        });
        Schema::table('brain_messages', fn (Blueprint $t) => $t->dropIndex(['workspace_id', 'role', 'created_at']));
        Schema::table('workspaces', fn (Blueprint $t) => $t->dropColumn('whatsapp'));
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::table('audit_logs', fn (Blueprint $t) => $t->dropColumn(['prev_hash', 'hash']));
        Schema::dropIfExists('auth_events');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'password_changed_at', 'last_login_at']));
    }
};
