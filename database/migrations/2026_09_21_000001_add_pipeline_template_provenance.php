<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipelines', function (Blueprint $table): void {
            $table->string('template_key', 80)->nullable();
            $table->boolean('auto_provisioned')->default(false);
        });

        DB::statement(
            'CREATE UNIQUE INDEX pipelines_auto_provisioned_organization_unique '
            .'ON pipelines (organization_id) '
            .'WHERE organization_id IS NOT NULL AND auto_provisioned IS TRUE'
        );
        DB::statement(
            'CREATE UNIQUE INDEX pipelines_auto_provisioned_solo_unique '
            .'ON pipelines (owner_user_id) '
            .'WHERE organization_id IS NULL AND auto_provisioned IS TRUE'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pipelines_auto_provisioned_organization_unique');
        DB::statement('DROP INDEX IF EXISTS pipelines_auto_provisioned_solo_unique');

        Schema::table('pipelines', function (Blueprint $table): void {
            $table->dropColumn(['template_key', 'auto_provisioned']);
        });
    }
};
