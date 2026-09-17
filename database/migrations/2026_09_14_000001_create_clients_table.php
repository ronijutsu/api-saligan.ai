<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->string('client_type', 20);
            $table->string('display_name', 255);
            $table->text('contact')->nullable();
            $table->text('notes')->nullable();
            $table->string('lifecycle', 20)->default('prospect');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'archived_at', 'updated_at', 'id']);
            $table->index(['owner_user_id', 'archived_at', 'updated_at', 'id']);
            $table->index(['organization_id', 'client_type', 'archived_at']);
            $table->index(['organization_id', 'lifecycle', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
