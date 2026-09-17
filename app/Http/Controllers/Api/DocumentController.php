<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentStatus;
use App\Enums\LabelKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\LabelResource;
use App\Jobs\ProcessDocumentUpload;
use App\Models\AiUsage;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Label;
use App\Models\LegalCase;
use App\Services\Billing\AiBudget;
use App\Services\Billing\AiCosting;
use App\Services\Crawler\LegalDigestService;
use App\Services\Documents\DocumentEncryptor;
use App\Services\Documents\DocumentIndexQuota;
use App\Support\PlanFeatures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentEncryptor $encryptor,
        private readonly DocumentIndexQuota $quota,
    ) {
        //
    }

    /**
     * List the documents the caller may open — their own uploads plus the file
     * shelves of the cases they are on — optionally scoped to a single case and
     * filtered by the case-file categories they are filed under.
     *
     * A document may sit in several categories at once, so `match` decides how
     * a multi-category filter reads: `any` widens the net, `all` narrows to the
     * documents doing every one of those jobs — the bank records that are both
     * documentary evidence and a financial record.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'case_id' => ['nullable', 'uuid', 'exists:cases,id'],
            'category_id' => ['nullable', 'array'],
            'category_id.*' => ['uuid'],
            'match' => ['nullable', 'in:any,all'],
            'uncategorized' => ['nullable', 'boolean'],
        ]);

        $query = Document::query()->withCount('chunks')->with(['labels', 'user:id,name']);

        if (isset($validated['case_id'])) {
            $case = LegalCase::findOrFail($validated['case_id']);
            $this->authorize('view', $case);

            // Scoped to the case rather than the caller: a case's file shelf is
            // shared by everyone on it, so an assignee must see what the owner
            // attached. The policy check above is what makes this safe.
            $query->where('case_id', $case->id);
        } else {
            // No case in play, so this is the library: what the caller
            // uploaded, plus the shelves of every case they are on. Scoping it
            // to `user_id` alone left an assignee looking at an empty library
            // for a matter their colleague had already filled with evidence.
            $query->visibleTo($request->user());
        }

        $categoryIds = $validated['category_id'] ?? [];

        if ($categoryIds !== []) {
            ($validated['match'] ?? 'any') === 'all'
                ? $query->withAllLabels($categoryIds)
                : $query->withAnyLabels($categoryIds);
        }

        if ($request->boolean('uncategorized')) {
            $query->whereDoesntHave('labels');
        }

        return DocumentResource::collection($query->latest()->get());
    }

    /**
     * Store an uploaded document and queue it for ingestion.
     */
    public function store(Request $request): JsonResponse
    {
        $maxKb = config('saligan.documents.max_size_mb') * 1024;

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                "max:{$maxKb}",
                Rule::file()->extensions(array_merge(
                    ['pdf', 'docx', 'txt', 'md'],
                    config('saligan.documents.image_extensions', []),
                )),
            ],
            'title' => ['nullable', 'string', 'max:255'],
            'case_id' => ['nullable', 'uuid', 'exists:cases,id'],
            'label_ids' => ['nullable', 'array'],
            'label_ids.*' => ['uuid'],
        ]);

        $labels = Label::resolveForAssignment(
            $request->user(),
            $validated['label_ids'] ?? [],
            LabelKind::DocumentCategory,
        );

        // The index is a finite resource that outlives the month's AI budget,
        // so a full account is told now rather than after the upload has been
        // accepted and the queue has tried to embed it.
        if (! $this->quota->hasRoom($request->user())) {
            abort(422, sprintf(
                'Your document index is full: %s of %s passages are in use. '
                .'Delete documents you no longer need, then upload again.',
                number_format($this->quota->used($request->user())),
                number_format($this->quota->limit()),
            ));
        }

        if (isset($validated['case_id'])) {
            $case = LegalCase::findOrFail($validated['case_id']);
            $this->authorize('update', $case);
        }

        $file = $validated['file'];
        $isImage = str_starts_with((string) $file->getClientMimeType(), 'image/');
        $isPdf = strtolower((string) $file->getClientOriginalExtension()) === 'pdf';

        if ($isPdf) {
            PlanFeatures::ensureHas($request->user(), PlanFeatures::PDF_DOCUMENTS);
        }

        // An image is nothing but a picture of text: without the OCR that
        // document intelligence pays for, ingesting it can only fail. Refusing
        // it here rather than in the queue means the upload allowance is not
        // spent on a document that was never going to be readable, and the
        // reader is told why while they are still looking at the picker.
        if ($isImage) {
            PlanFeatures::ensureHas($request->user(), PlanFeatures::DOCUMENT_INTELLIGENCE);
        }

        // Ingestion spends from the AI allowance, not an upload count: the
        // scan branch reserves the heavier estimate up front, and the job
        // settles the measured cost or releases the hold on failure.
        $reservation = AiBudget::reserve(
            $request->user(),
            AiUsage::OPERATION_INGEST,
            AiCosting::estimateFor($isImage ? 'ingest_scan' : 'ingest_text'),
            context: ['engine' => config('saligan.ai_provider.batch_engine')],
        );

        $originalFilename = $file->getClientOriginalName();

        $storagePath = 'documents/'.Str::uuid().'.'.($file->getClientOriginalExtension() ?: 'bin');

        if (config('saligan.documents.encrypt_at_rest', true)) {
            $this->encryptor->encrypt((string) ($file->getRealPath() ?: $file->getPathname()), $storagePath);
        } else {
            $file->storeAs('documents', basename($storagePath));
        }

        $document = Document::create([
            'user_id' => $request->user()->id,
            'case_id' => $validated['case_id'] ?? null,
            'title' => $validated['title'] ?? pathinfo($originalFilename, PATHINFO_FILENAME),
            'original_filename' => $originalFilename,
            'storage_path' => $storagePath,
            'mime_type' => $file->getClientMimeType(),
            'status' => DocumentStatus::Queued,
            'ai_usage_id' => $reservation->id,
        ]);

        if ($labels->isNotEmpty()) {
            $document->syncLabels($labels, $request->user());
        }

        ProcessDocumentUpload::dispatch($document)
            ->onQueue(config('saligan.documents.queue'));

        return (new DocumentResource($document->load(['labels', 'user:id,name'])))->response()->setStatusCode(201);
    }

    /**
     * Show a single document.
     */
    public function show(Request $request, Document $document): DocumentResource
    {
        abort_unless($document->isAccessibleBy($request->user()), 403);

        return new DocumentResource($document->load(['labels', 'user:id,name'])->loadCount('chunks'));
    }

    /**
     * Rename a document and file it under case-file categories.
     *
     * Categories are replaced wholesale rather than merged: the picker sends
     * the full set it is showing, so a category the user cleared has to come
     * back off the document.
     */
    public function update(Request $request, Document $document): DocumentResource
    {
        abort_unless($document->isAccessibleBy($request->user()), 403);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'label_ids' => ['sometimes', 'array'],
            'label_ids.*' => ['uuid'],
        ]);

        if (array_key_exists('title', $validated)) {
            $document->update(['title' => $validated['title']]);
        }

        if (array_key_exists('label_ids', $validated)) {
            $document->syncLabels(
                Label::resolveForAssignment($request->user(), $validated['label_ids'], LabelKind::DocumentCategory),
                $request->user(),
            );
        }

        return new DocumentResource($document->load(['labels', 'user:id,name'])->loadCount('chunks'));
    }

    /**
     * Attach a document the caller can open to one of their cases so it becomes
     * retrievable within that case's conversations.
     *
     * Refiling is shared work like the rest of the shelf, so an assignee may
     * move a colleague's document too — the `update` check on the destination
     * case is what keeps it from landing somewhere they do not belong.
     */
    public function attach(Request $request, Document $document): DocumentResource
    {
        abort_unless($document->isAccessibleBy($request->user()), 403);

        $validated = $request->validate([
            'case_id' => ['required', 'uuid', 'exists:cases,id'],
        ]);

        $case = LegalCase::findOrFail($validated['case_id']);
        $this->authorize('update', $case);

        // Being allowed to read a document and update a case is not the same as
        // being allowed to join the two: filing it puts the document on that
        // case's shelf, where everyone on the case can read it. So a document
        // already held by an organization's case may only be filed into another
        // case of the same organization — without this, someone on a firm
        // matter could move a client's document to a case outside the firm and
        // keep reading it once their firm access ends. A document that no
        // organization holds yet (a personal library upload) is unconstrained:
        // it is joining a shelf, not leaving one.
        $currentCase = $document->case;

        if ($currentCase?->organization_id !== null) {
            abort_unless(
                $case->organization_id === $currentCase->organization_id,
                403,
                'A document can only be filed into a case in the same organization.',
            );
        }

        $document->update(['case_id' => $case->id]);

        return new DocumentResource($document->loadCount('chunks'));
    }

    /**
     * Re-queue a document whose ingestion failed so processing runs again.
     * Only a failed document can be retried: the queued and processing states
     * already have a worker on them, and a ready document has nothing left to
     * do.
     */
    public function retry(Request $request, Document $document): DocumentResource
    {
        abort_unless($document->isAccessibleBy($request->user()), 403);

        if ($this->isPdf($document)) {
            PlanFeatures::ensureHas($request->user(), PlanFeatures::PDF_DOCUMENTS);
        }

        abort_unless($document->status === DocumentStatus::Failed, 422, 'Only a failed document can be retried.');

        // The failed attempt's hold was released when it failed, so the retry
        // reserves anew: exactly one successful ingestion ever settles.
        $retryReservation = AiBudget::reserve(
            $request->user(),
            AiUsage::OPERATION_INGEST,
            AiCosting::estimateFor(str_starts_with((string) $document->mime_type, 'image/') ? 'ingest_scan' : 'ingest_text'),
            context: ['engine' => config('saligan.ai_provider.batch_engine')],
        );

        $document->update([
            'status' => DocumentStatus::Queued,
            'error_message' => null,
            'ai_usage_id' => $retryReservation->id,
        ]);

        $document->chunks()->delete();

        ProcessDocumentUpload::dispatch($document)
            ->onQueue(config('saligan.documents.queue'));

        return new DocumentResource($document->load(['labels', 'user:id,name'])->loadCount('chunks'));
    }

    /**
     * The extracted text of an uploaded document, for reading a citation in
     * place.
     *
     * The chunks double as paragraphs here for the same reason they do on
     * crawled authorities: they are the units retrieval cites, so returning
     * them with their indices lets the citation reader highlight exactly the
     * passage an answer relied on instead of approximating it with a search.
     */
    public function content(Request $request, Document $document): JsonResponse
    {
        abort_unless($document->isAccessibleBy($request->user()), 403);

        if ($this->isPdf($document)) {
            PlanFeatures::ensureHas($request->user(), PlanFeatures::PDF_DOCUMENTS);
        }

        $chunks = $document->chunks()
            ->orderBy('chunk_index')
            ->get(['id', 'chunk_index', 'content']);

        $this->ensureDigest($document, $chunks->pluck('content')->implode("\n\n"));

        $document->loadMissing('labels');

        return response()->json([
            'data' => [
                'id' => $document->id,
                'title' => $document->title,
                'original_filename' => $document->original_filename,
                'mime_type' => $document->mime_type,
                'status' => $document->status->value,
                'digest' => $document->digest,
                // Absent when the document was uploaded before digests existed,
                // or when the extracted text was too fragmentary to digest. The
                // reader shows the full text either way.
                'has_digest' => filled($document->digest),
                'categories' => LabelResource::collection($document->labels),
                'uploaded_at' => $document->created_at,
                'chunks' => $chunks->map(fn (DocumentChunk $chunk) => [
                    'id' => $chunk->id,
                    'index' => $chunk->chunk_index,
                    'content' => $chunk->content,
                ]),
            ],
        ]);
    }

    /**
     * Digest a document the first time someone reads a citation to it.
     *
     * Digesting at ingestion would put a model call behind every upload,
     * including the many that are never cited; doing it on first read spends
     * that only on documents the answer actually leaned on. The lock stops two
     * readers opening the same citation at once from both paying for it.
     */
    protected function ensureDigest(Document $document, string $text): void
    {
        if (filled($document->digest) || trim($text) === '') {
            return;
        }

        $lock = Cache::lock('document-digest:'.$document->id, 60);

        if (! $lock->get()) {
            return;
        }

        try {
            $digest = app(LegalDigestService::class)->generate($text, $document->title);

            if ($digest !== null) {
                $document->forceFill([
                    'digest' => $digest,
                    'digest_generated_at' => now(),
                ])->save();
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Serve the stored file to its owner. PDFs, images, and plain-text files
     * are served inline so they can be previewed in the browser; everything
     * else is served as an attachment. Clients may force either disposition
     * via the `disposition` query parameter.
     */
    public function file(Request $request, Document $document): StreamedResponse
    {
        abort_unless($document->isAccessibleBy($request->user()), 403);

        if ($this->isPdf($document)) {
            PlanFeatures::ensureHas($request->user(), PlanFeatures::PDF_DOCUMENTS);
        }

        $validated = $request->validate([
            'disposition' => ['nullable', 'in:inline,attachment'],
        ]);

        if (! Storage::exists($document->storage_path)) {
            abort(404, 'The file is no longer available.');
        }

        // Derived from the stored extension, never from `mime_type`: that column
        // holds the browser-supplied type from upload time, so honouring it here
        // would let an uploader pick the Content-Type their file is served
        // under — `text/html` on an allowed extension, rendered in the app's own
        // origin. The extension is trustworthy because upload validation checks
        // it against the file's real contents.
        $mimeType = $this->safeMimeType($document->storage_path);

        $disposition = $validated['disposition'] ?? $this->defaultDisposition($mimeType);

        // A caller may force a download, but never force something viewable
        // that the type does not support.
        if ($disposition === 'inline' && ! $this->isViewable($mimeType)) {
            $disposition = 'attachment';
        }

        if ($this->encryptor->isEncrypted($document->storage_path)) {
            return $this->streamDecryptedFile(
                $document->storage_path,
                $document->original_filename,
                $mimeType,
                $disposition,
            );
        }

        return Storage::response(
            $document->storage_path,
            $document->original_filename,
            [
                'Content-Type' => $mimeType,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
            $disposition,
        );
    }

    /**
     * Stream an encrypted stored document after decrypting it on the fly, so
     * the plaintext never touches the disk during a download.
     */
    protected function streamDecryptedFile(string $storagePath, string $filename, string $mimeType, string $disposition): StreamedResponse
    {
        // Opened and integrity-checked here rather than inside the callback:
        // once the callback runs the 200 is already on the wire, so a file that
        // fails its check would reach the user as a truncated download instead
        // of an error.
        $chunks = $this->encryptor->decryptStream($storagePath);

        return response()->streamDownload(
            function () use ($chunks): void {
                foreach ($chunks as $chunk) {
                    echo $chunk;
                }
            },
            $filename,
            [
                'Content-Type' => $mimeType,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
            $disposition,
        );
    }

    /**
     * Delete a document and its chunks (cascaded).
     */
    public function destroy(Request $request, Document $document): JsonResponse
    {
        abort_unless($document->isDeletableBy($request->user()), 403);

        Storage::delete($document->storage_path);

        $document->delete();

        return response()->json(null, 204);
    }

    /**
     * The Content-Type a stored file may be served under, mapped from its
     * extension. Anything not on the list — including the extensions uploads
     * accept but browsers should never render — downloads as a binary blob.
     */
    private function safeMimeType(string $storagePath): string
    {
        return match (strtolower(pathinfo($storagePath, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'md' => 'text/markdown',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'tiff' => 'image/tiff',
            'heic' => 'image/heic',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            default => 'application/octet-stream',
        };
    }

    private function isPdf(Document $document): bool
    {
        return strtolower(pathinfo($document->storage_path, PATHINFO_EXTENSION)) === 'pdf'
            || $document->mime_type === 'application/pdf';
    }

    /**
     * Whether the browser can render the MIME type inline.
     */
    private function isViewable(string $mimeType): bool
    {
        return in_array($mimeType, ['application/pdf', 'text/plain', 'text/markdown', 'text/md'], true)
            || str_starts_with($mimeType, 'image/');
    }

    /**
     * Serve viewable types inline, everything else as a download.
     */
    private function defaultDisposition(string $mimeType): string
    {
        return $this->isViewable($mimeType) ? 'inline' : 'attachment';
    }
}
