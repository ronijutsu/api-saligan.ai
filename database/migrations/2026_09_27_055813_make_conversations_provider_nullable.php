<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ai-provider chooses which provider answers a turn, so Laravel no longer
 * knows it when a conversation is created. The column now records the
 * provider that last answered, and stays null until one has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('provider', 20)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        DB::table('conversations')->whereNull('provider')->update(['provider' => 'ollama']);

        Schema::table('conversations', function (Blueprint $table) {
            $table->string('provider', 20)->default('ollama')->nullable(false)->change();
        });
    }
};
