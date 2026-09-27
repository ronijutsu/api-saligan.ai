<?php

namespace App\Services\Documents;

use App\Enums\LabelKind;
use App\Models\Document;
use App\Models\DocumentClassificationRequest;
use App\Models\Label;
use App\Services\Ai\PythonAiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Files a freshly ingested document under the case-file categories it belongs
 * to, so a lawyer opens a case with the material already sorted instead of
 * facing a flat list of uploads.
 *
 * Classification runs in ai-provider: inline through `POST /documents/classify`
 * and, when batching is on, through the batch routes driven by
 * {@see DocumentClassificationBatcher}. This class keeps no provider client of
 * its own — the batch lifecycle lives in Python (ADR-011).
 */
class DocumentClassifier
{
    public function __construct(
        private readonly PythonAiClient $python,
    ) {}

    /**
     * Suggest and apply categories for a document.
     *
     * Never throws: a document that cannot be classified is still a perfectly
     * good document, and the ingestion that produced it must not fail over a
     * filing suggestion. An unclassified document surfaces in the Unfiled
     * queue, which is the same place a low-confidence answer leaves it.
     */
    public function classify(Document $document, string $text): void
    {
        if (! config('saligan.documents.classification.enabled', true)) {
            return;
        }

        try {
            if ($this->wasFiledByHand($document)) {
                return;
            }

            $vocabulary = $this->vocabularyFor($document);

            if ($vocabulary->isEmpty()) {
                return;
            }

            // Batched: the document is queued and filed by a later sweep,
            // which costs half as much and arrives within the hour. Nothing
            // downstream waits on the filing, so ingestion carries on.
            if ($this->batches()) {
                $this->enqueue($document, $text);

                return;
            }

            $suggestions = $this->suggest($document, $text, $vocabulary);

            if ($suggestions === []) {
                return;
            }

            $document->syncSuggestedLabels($suggestions);
        } catch (Throwable $exception) {
            Log::warning('Document classification failed; the document was left unfiled.', [
                'document_id' => $document->id,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Ask the model which categories the document belongs to, and keep only
     * the answers confident enough to act on.
     *
     * @param  Collection<int, Label>  $vocabulary
     * @return array<int, array{label: Label, confidence: float}>
     */
    public function suggest(Document $document, string $text, Collection $vocabulary): array
    {
        $response = $this->python->call('/documents/classify', [
            'filename' => (string) $document->original_filename,
            'title' => (string) $document->title,
            'text' => mb_substr(
                $text,
                0,
                (int) config('saligan.documents.classification.excerpt_characters', 6000),
            ),
            'vocabulary' => $vocabulary->map(fn (Label $label): array => [
                'slug' => $label->slug,
                'name' => $label->name,
                'description' => $label->description,
            ])->values()->all(),
        ]);

        return $this->selectConfident(
            $this->candidatesFrom($response['categories'] ?? []),
            $vocabulary,
        );
    }

    /**
     * Queue a document for the next classification batch, replacing any
     * request already queued for it — a re-ingested document is classified
     * against the text it has now, not the text it had on the first attempt.
     *
     * The excerpt is stored rather than the full extraction: it is the opening
     * of the document, which is where the filing signal lives, and it is handed
     * to the provider as the batch request's `text` (ADR-011).
     */
    public function enqueue(Document $document, string $text): DocumentClassificationRequest
    {
        return DocumentClassificationRequest::updateOrCreate(
            ['document_id' => $document->id],
            [
                'excerpt' => Str::limit(
                    trim($text),
                    (int) config('saligan.documents.classification.excerpt_characters', 6000),
                    '…',
                ),
                'status' => DocumentClassificationRequest::STATUS_PENDING,
                'batch_id' => null,
                'error' => null,
                'submitted_at' => null,
                'completed_at' => null,
            ],
        );
    }

    /**
     * File a document from an answer that arrived later.
     *
     * The eligibility checks run again here rather than being trusted from
     * submission time: a batch takes up to a day, and in that time somebody
     * may have filed the document by hand — their filing wins — or the firm's
     * categories may have changed underneath it.
     *
     * @param  array<int, array{slug: string, confidence: float}>  $candidates
     */
    public function apply(Document $document, array $candidates): void
    {
        if ($this->wasFiledByHand($document)) {
            return;
        }

        $vocabulary = $this->vocabularyFor($document);

        if ($vocabulary->isEmpty()) {
            return;
        }

        $suggestions = $this->selectConfident($candidates, $vocabulary);

        if ($suggestions === []) {
            return;
        }

        $document->syncSuggestedLabels($suggestions);
    }

    /**
     * The providers ai-provider can batch on. Kept in step with the backends
     * it implements: a deployment classifying on anything else is answered
     * inline, because enqueueing work nothing will ever collect leaves the
     * document unfiled forever rather than merely late.
     *
     * @var array<int, string>
     */
    protected const BATCH_PROVIDERS = ['gemini'];

    /**
     * Whether classification should be batched rather than answered inline.
     */
    public function batches(): bool
    {
        if (! config('saligan.documents.classification.batch.enabled', false)) {
            return false;
        }

        return $this->batchProvider() !== null;
    }

    /**
     * The provider batched classification runs on, or null when this
     * deployment is not classifying on one that batches.
     */
    public function batchProvider(): ?string
    {
        $provider = (string) config('saligan.documents.classification.provider', 'gemini');

        return in_array($provider, self::BATCH_PROVIDERS, true) ? $provider : null;
    }

    /**
     * The categories this document's owner may file under: the seeded system
     * vocabulary plus whatever their firm has added.
     *
     * @return Collection<int, Label>
     */
    public function vocabularyFor(Document $document): Collection
    {
        $owner = $document->user;

        if ($owner === null) {
            return new Collection;
        }

        return Label::query()
            ->visibleTo($owner)
            ->active()
            ->where('kind', LabelKind::DocumentCategory)
            ->orderBy('position')
            ->get();
    }

    /**
     * Whether a person has already filed this document. Their filing is the
     * answer, and no later pass may overwrite it.
     */
    protected function wasFiledByHand(Document $document): bool
    {
        return $document->labels()->wherePivot('source', 'user')->exists();
    }

    /**
     * Normalise the model's category list, whichever shape it arrived in.
     *
     * @return array<int, array{slug: string, confidence: float}>
     */
    protected function candidatesFrom(mixed $categories): array
    {
        if (! is_array($categories)) {
            return [];
        }

        $candidates = [];

        foreach ($categories as $category) {
            if (! is_array($category) || ! isset($category['slug'])) {
                continue;
            }

            $candidates[] = [
                'slug' => (string) $category['slug'],
                'confidence' => (float) ($category['confidence'] ?? 0.0),
            ];
        }

        return $candidates;
    }

    /**
     * Keep the confident, known, distinct answers — most confident first — and
     * no more of them than a document is allowed to carry.
     *
     * @param  array<int, array{slug: string, confidence: float}>  $candidates
     * @param  Collection<int, Label>  $vocabulary
     * @return array<int, array{label: Label, confidence: float}>
     */
    protected function selectConfident(array $candidates, Collection $vocabulary): array
    {
        $minConfidence = (float) config('saligan.documents.classification.min_confidence', 0.6);

        $limit = min(
            (int) config('saligan.documents.classification.max_categories', 3),
            LabelKind::DocumentCategory->maxPerRecord(),
        );

        $bySlug = $vocabulary->keyBy('slug');

        return (new Collection($candidates))
            ->filter(fn (array $candidate): bool => $candidate['confidence'] >= $minConfidence)
            ->filter(fn (array $candidate): bool => $bySlug->has($candidate['slug']))
            ->sortByDesc('confidence')
            ->unique('slug')
            ->take($limit)
            ->map(fn (array $candidate): array => [
                'label' => $bySlug->get($candidate['slug']),
                'confidence' => $candidate['confidence'],
            ])
            ->values()
            ->all();
    }
}
