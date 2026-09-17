<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_stage_changes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('pipeline_item_id');
            $table->uuid('previous_stage_id')->nullable();
            $table->uuid('new_stage_id');
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('request_id', 128)->nullable();
            $table->timestamps();

            $table->foreign('pipeline_item_id')->references('id')->on('pipeline_items')->restrictOnDelete();
            $table->foreign('previous_stage_id')->references('id')->on('pipeline_stages')->restrictOnDelete();
            $table->foreign('new_stage_id')->references('id')->on('pipeline_stages')->restrictOnDelete();

            $table->index(['pipeline_item_id', 'created_at', 'id']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX pipeline_stage_changes_item_request_unique '
            .'ON pipeline_stage_changes (pipeline_item_id, request_id) WHERE request_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pipeline_stage_changes_item_request_unique');
        Schema::dropIfExists('pipeline_stage_changes');
    }
};
