<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The chunk tables whose embeddings carry an HNSW index.
     *
     * @var array<int, string>
     */
    private const TABLES = ['document_chunks', 'legal_chunks'];

    /**
     * Pin the HNSW build parameters explicitly.
     *
     * Both indexes were created without parameters, which means they silently
     * took whatever pgvector's defaults were on the day the migration ran. The
     * parameters are not cosmetic: `m` sets how many neighbour links are stored
     * per element and therefore how large the index grows — roughly 2 kB per
     * row at m=16, measured — while `ef_construction` trades build time for
     * recall. Pinning them means a pgvector upgrade cannot change the storage
     * cost or the recall of an index that already holds a customer's documents
     * without anyone deciding to.
     *
     * A 256 MB working memory is set for the rebuild: the default is 64 MB,
     * which is below the size of the index as soon as a tenant has a few
     * hundred megabytes of vectors, and a build that spills to disk is both
     * slow and worse quality.
     */
    public function up(): void
    {
        DB::statement("SET maintenance_work_mem = '256MB'");

        foreach (self::TABLES as $table) {
            $this->replaceIndex($table, m: 16, efConstruction: 64);
        }
    }

    /**
     * Restore the indexes without explicit parameters, i.e. pgvector defaults.
     */
    public function down(): void
    {
        DB::statement("SET maintenance_work_mem = '256MB'");

        foreach (self::TABLES as $table) {
            $this->replaceIndex($table);
        }
    }

    private function replaceIndex(string $table, ?int $m = null, ?int $efConstruction = null): void
    {
        $index = "{$table}_embedding_hnsw_index";
        $column = 'embedding halfvec_cosine_ops';

        $with = $m === null && $efConstruction === null
            ? ''
            : sprintf(
                ' WITH (m = %d, ef_construction = %d)',
                $m ?? 16,
                $efConstruction ?? 64,
            );

        // CONCURRENTLY is deliberately not used: it cannot run inside the
        // transaction a migration is wrapped in, and the tables are small
        // relative to the write lock this takes on a deploy.
        DB::statement("DROP INDEX IF EXISTS {$index}");
        DB::statement("CREATE INDEX {$index} ON {$table} USING hnsw ({$column}){$with}");
    }
};
