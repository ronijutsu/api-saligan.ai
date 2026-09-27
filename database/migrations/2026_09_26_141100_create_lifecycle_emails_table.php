<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per lifecycle email actually sent (or deliberately skipped), so
     * each step of a sequence goes to a person at most once no matter how
     * often the sweep runs.
     */
    public function up(): void
    {
        Schema::create('lifecycle_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('sequence', 40);
            $table->unsignedTinyInteger('step');
            // "sent", or "skipped" when the user had already done what the
            // email asks — recorded so a later sweep does not reconsider it.
            $table->string('outcome', 20);
            $table->timestamps();

            $table->unique(['user_id', 'sequence', 'step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lifecycle_emails');
    }
};
