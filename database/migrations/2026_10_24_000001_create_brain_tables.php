<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brain_conversations', function (Blueprint $table) {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->string('title')->nullable();
            $table->string('provider')->nullable();
            $table->string('user_ref')->nullable()->index(); // your user id / customer id / channel id
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('brain_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('brain_conversations')->cascadeOnDelete();
            $table->string('role', 16);
            $table->longText('content')->nullable();
            $table->json('tool_calls')->nullable();
            $table->string('tool_call_id')->nullable();
            $table->string('tool_name')->nullable();
            $table->json('sources')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->timestamps();
            $table->index(['conversation_id', 'id']);
        });

        Schema::create('brain_documents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('collection')->default('default')->index();
            $table->string('source_type', 16)->default('text'); // file | text | url
            $table->string('path')->nullable();
            $table->string('mime')->nullable();
            $table->string('hash', 64)->nullable()->index();
            $table->string('status', 16)->default('pending'); // pending | processing | ready | failed
            $table->text('error')->nullable();
            $table->unsignedInteger('chunk_count')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('brain_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('brain_documents')->cascadeOnDelete();
            $table->string('collection')->default('default')->index();
            $table->unsignedInteger('position');
            $table->longText('content');
            $table->longText('embedding')->nullable(); // JSON float array (database store)
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb') {
            DB::statement('ALTER TABLE brain_chunks ADD FULLTEXT brain_chunks_content_fulltext (content)');
        }

        if (DB::getDriverName() === 'pgsql' && config('brain.vector_store') === 'pgvector') {
            $dim = (int) config('brain.embeddings.dimensions', 1536);
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
            DB::statement("ALTER TABLE brain_chunks ADD COLUMN embedding_vec vector({$dim})");
            if ($dim <= 2000) {
                DB::statement('CREATE INDEX brain_chunks_embedding_hnsw ON brain_chunks USING hnsw (embedding_vec vector_cosine_ops)');
            }
        }

        Schema::create('brain_leads', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable();
            $table->string('company')->nullable();
            $table->string('status', 24)->default('new')->index(); // new|contacted|qualified|proposal|won|lost
            $table->decimal('value', 14, 2)->nullable();
            $table->string('source')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('brain_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('due_date')->nullable();
            $table->string('priority', 12)->default('normal'); // low|normal|high|urgent
            $table->string('status', 12)->default('open')->index(); // open|done
            $table->string('assignee')->nullable();
            $table->foreignId('lead_id')->nullable()->constrained('brain_leads')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('brain_pending_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained('brain_conversations')->nullOnDelete();
            $table->string('tool');
            $table->json('arguments');
            $table->string('status', 16)->default('pending')->index(); // pending|approved|rejected|failed
            $table->longText('result')->nullable();
            $table->string('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['brain_pending_actions', 'brain_tasks', 'brain_leads', 'brain_chunks', 'brain_documents', 'brain_messages', 'brain_conversations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
