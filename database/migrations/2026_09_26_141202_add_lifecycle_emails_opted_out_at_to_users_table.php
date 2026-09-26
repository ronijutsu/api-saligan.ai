<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Set from the unsubscribe link in lifecycle emails. Covers onboarding and
     * marketing mail only — account, billing, and deadline emails still send.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('lifecycle_emails_opted_out_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('lifecycle_emails_opted_out_at');
        });
    }
};
