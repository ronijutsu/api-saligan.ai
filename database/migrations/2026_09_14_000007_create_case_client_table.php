<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_client', function (Blueprint $table): void {
            $table->uuid('case_id');
            $table->uuid('client_id');
            $table->string('relationship_type', 30);
            $table->boolean('is_primary')->default(false);
            $table->foreignId('attached_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->foreign('case_id')->references('id')->on('cases')->restrictOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->restrictOnDelete();
            $table->unique(['case_id', 'client_id']);
            $table->index(['client_id', 'case_id']);
            $table->index(['case_id', 'client_id']);
        });

        DB::statement('CREATE UNIQUE INDEX case_client_one_primary ON case_client (case_id) WHERE is_primary = true');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS case_client_one_primary');
        Schema::dropIfExists('case_client');
    }
};
