<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->uuid('pipeline_id');
            $table->uuid('stage_id');
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->string('title', 255);
            $table->string('source', 120)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('next_action_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->restrictOnDelete();
            $table->foreign('pipeline_id')->references('id')->on('pipelines')->restrictOnDelete();
            $table->foreign('stage_id')->references('id')->on('pipeline_stages')->restrictOnDelete();

            $table->index(['pipeline_id', 'stage_id', 'archived_at', 'updated_at', 'id']);
            $table->index(['client_id', 'archived_at', 'updated_at', 'id']);
            $table->index(['owner_user_id', 'archived_at', 'updated_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_items');
    }
};
