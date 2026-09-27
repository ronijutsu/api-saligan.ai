<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The queued column holds the document's opening excerpt, not a rendered
 * prompt: digest batching moved into ai-provider (ADR-011 generalised), which
 * builds the turn from `text`, `title` and `kind`. Renaming keeps the column
 * honest about what it carries. Reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_digest_requests', function (Blueprint $table) {
            $table->renameColumn('prompt', 'excerpt');
        });
    }

    public function down(): void
    {
        Schema::table('legal_digest_requests', function (Blueprint $table) {
            $table->renameColumn('excerpt', 'prompt');
        });
    }
};
