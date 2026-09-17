<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record which embedding model produced a document's vectors.
     *
     * The index cannot detect a model switch on its own: two models can emit
     * the same 768 dimensions, so every insert still succeeds while retrieval
     * quietly degrades — a query vector from the new model compared against
     * corpus vectors from the old one. Storing the model makes the switch
     * visible, so ingestion can refuse it until the corpus is re-embedded.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('embedding_model', 120)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['embedding_model']);
            $table->dropColumn('embedding_model');
        });
    }
};
