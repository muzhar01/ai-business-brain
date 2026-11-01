<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** v4: single sign-on identities and the Slack channel. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_id', 191);
            $table->string('email')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change(); // SSO-only users have no password
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->text('slack')->nullable(); // encrypted JSON: bot_token, signing_secret
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', fn (Blueprint $t) => $t->dropColumn('slack'));
        Schema::dropIfExists('social_accounts');
    }
};
