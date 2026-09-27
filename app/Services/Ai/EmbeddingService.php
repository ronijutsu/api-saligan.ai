<?php

namespace App\Services\Ai;

use App\Exceptions\DocumentProcessingException;

class EmbeddingService
{
    public function __construct(
        private readonly PythonAiClient $python,
    ) {}

    /**
     * The provider and model every new vector will come from.
     *
     * Recorded alongside each document so a later change of model can be
     * detected instead of quietly mixing two models' vectors in one index.
     */
    public function model(): string
    {
        return config('saligan.embedding.provider').'/'.config('saligan.embedding.model');
    }

    /**
     * Refuse to embed when the index already holds vectors from another model.
     *
     * Two models can emit the same number of dimensions, so Postgres cannot
     * catch the switch: the inserts still succeed and retrieval silently gets
     * worse, because a query vector from model B is compared against corpus
     * vectors from model A. Changing the model is legitimate, but it means
     * re-embedding the corpus — so it has to be a decision, not an accident.
     */
    public function assertModelMatchesIndex(?string $indexedModel): void
    {
        if ($indexedModel === null || $indexedModel === '' || $indexedModel === $this->model()) {
            return;
        }

        throw new DocumentProcessingException(sprintf(
            'This index was built with the embedding model %s, but %s is configured. '
            .'Re-embed the existing documents before switching models, or restore the previous model.',
            $indexedModel,
            $this->model(),
        ));
    }

    /**
     * Generate an embedding vector for the given text.
     *
     * @return array<int, float>
     */
    public function embed(string $text): array
    {
        return $this->embedMany([$text])[0];
    }

    /**
     * Generate embedding vectors for the given texts.
     *
     * qwen3-embedding emits 768 dimensions in this setup; the stored prefix
     * length is configured via EMBEDDING_DIMENSIONS and must match the
     * halfvec(768) columns and their indexes.
     *
     * Batching and the provider's request-size limit are ai-provider's: it
     * chunks past 128 texts and checks the returned count before this sees a
     * vector, so the two engines cannot disagree about dimensions or ordering.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function embedMany(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        return $this->python->embeddings($texts);
    }
}
