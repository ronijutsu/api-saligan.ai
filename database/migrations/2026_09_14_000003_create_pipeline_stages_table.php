<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_stages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('pipeline_id');
            $table->string('name', 120);
            $table->unsignedInteger('position');
            $table->string('outcome_kind', 30)->default('open');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('pipeline_id')->references('id')->on('pipelines')->restrictOnDelete();
            $table->index(['pipeline_id', 'position', 'id']);
            $table->index(['pipeline_id', 'archived_at', 'updated_at', 'id']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX pipeline_stages_active_position_unique '
            .'ON pipeline_stages (pipeline_id, position) WHERE archived_at IS NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX pipeline_stages_active_name_unique '
            .'ON pipeline_stages (pipeline_id, name) WHERE archived_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pipeline_stages_active_position_unique');
        DB::statement('DROP INDEX IF EXISTS pipeline_stages_active_name_unique');
        Schema::dropIfExists('pipeline_stages');
    }
};
