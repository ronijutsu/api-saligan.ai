<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_idempotency_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope_key', 255);
            $table->string('idempotency_key', 128);
            $table->char('request_fingerprint', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_headers')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['scope_key', 'idempotency_key']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_idempotency_records');
    }
};
