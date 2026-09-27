<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipelines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'archived_at', 'updated_at', 'id']);
            $table->index(['owner_user_id', 'archived_at', 'updated_at', 'id']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX pipelines_active_default_organization_unique '
            .'ON pipelines (organization_id) '
            .'WHERE organization_id IS NOT NULL AND archived_at IS NULL AND is_default IS TRUE'
        );
        DB::statement(
            'CREATE UNIQUE INDEX pipelines_active_default_solo_unique '
            .'ON pipelines (owner_user_id) '
            .'WHERE organization_id IS NULL AND archived_at IS NULL AND is_default IS TRUE'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pipelines_active_default_organization_unique');
        DB::statement('DROP INDEX IF EXISTS pipelines_active_default_solo_unique');
        Schema::dropIfExists('pipelines');
    }
};
