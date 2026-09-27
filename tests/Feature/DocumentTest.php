<?php

use App\Enums\DocumentStatus;
use App\Jobs\ProcessDocumentUpload;
use App\Models\Document;
use App\Models\LegalCase;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Documents\DocumentEncryptor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->user = User::factory()->create();
    Subscription::factory()->for($this->user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);
});

/**
 * An organization owner carrying the team subscription the plan gate requires,
 * so the re-filing guards can be exercised inside a firm's scope.
 *
 * @return array{0: Organization, 1: User}
 */
function organizationLawyer(): array
{
    $organization = Organization::factory()->create();
    $lawyer = User::factory()->ownerOf($organization)->create();

    // The slug is unique, and this file's beforeEach has already created the
    // Pro plan — so reuse it rather than inserting a second one.
    $plan = Plan::query()->firstWhere('slug', Plan::SLUG_PRO)
        ?? Plan::factory()->pro()->create();

    Subscription::factory()->for($lawyer)->create([
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
        'seats_purchased' => 5,
    ]);

    return [$organization, $lawyer];
}

it('requires authentication', function () {
    $this->getJson('/api/documents')->assertStatus(401);
});

it('lists only the authenticated user documents', function () {
    $own = Document::factory()->for($this->user)->create();
    Document::factory()->for(User::factory())->create();

    $response = $this->signInAs($this->user)
        ->getJson('/api/documents')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($own->id);
});

it('stores an uploaded document and queues ingestion', function () {
    Queue::fake();
    Storage::fake('local');

    $response = $this->signInAs($this->user)
        ->postJson('/api/documents', [
            'file' => UploadedFile::fake()->createWithContent('memo.txt', 'Plain legal notes.'),
            'title' => 'My memo',
        ])
        ->assertCreated();

    expect($response->json('data.title'))->toBe('My memo')
        ->and($response->json('data.status'))->toBe(DocumentStatus::Queued->value);

    $this->assertDatabaseHas('documents', [
        'user_id' => $this->user->id,
        'status' => DocumentStatus::Queued->value,
    ]);

    Queue::assertPushed(ProcessDocumentUpload::class);
});

it('stores an uploaded document encrypted at rest', function () {
    Queue::fake();
    Storage::fake('local');

    $this->signInAs($this->user)
        ->postJson('/api/documents', [
            'file' => UploadedFile::fake()->createWithContent('memo.txt', 'Plain legal notes.'),
            'title' => 'My memo',
        ])
        ->assertCreated();

    $path = Document::where('user_id', $this->user->id)->firstOrFail()->storage_path;

    expect(Storage::get($path))
        ->toStartWith(DocumentEncryptor::MAGIC)
        ->not->toContain('Plain legal notes.');

    $decrypted = app(DocumentEncryptor::class)->decryptToTemp($path);

    expect($decrypted)->not->toBeNull();

    $content = file_get_contents($decrypted);
    @unlink($decrypted);

    expect($content)->toBe('Plain legal notes.');
});

it('accepts image uploads for OCR processing', function () {
    Queue::fake();
    Storage::fake('local');

    $response = $this->signInAs($this->user)
        ->postJson('/api/documents', [
            'file' => UploadedFile::fake()->image('scan.png'),
        ])
        ->assertCreated();

    expect($response->json('data.mime_type'))->toBe('image/png')
        ->and($response->json('data.status'))->toBe(DocumentStatus::Queued->value);

    $this->assertDatabaseHas('documents', [
        'user_id' => $this->user->id,
        'mime_type' => 'image/png',
    ]);

    Queue::assertPushed(ProcessDocumentUpload::class);
});

it('rejects unsupported file types', function () {
    Queue::fake();

    $this->signInAs($this->user)
        ->postJson('/api/documents', [
            'file' => UploadedFile::fake()->createWithContent('malware.exe', 'x'),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    Queue::assertNotPushed(ProcessDocumentUpload::class);
});

it('shows a single document with chunk count', function () {
    $document = Document::factory()->for($this->user)->create();

    $this->signInAs($this->user)
        ->getJson("/api/documents/{$document->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $document->id);
});

it('forbids showing another user document', function () {
    $document = Document::factory()->for(User::factory())->create();

    $this->signInAs($this->user)
        ->getJson("/api/documents/{$document->id}")
        ->assertForbidden();
});

it('deletes a document and its stored file', function () {
    Storage::fake('local');

    $document = Document::factory()->for($this->user)->create([
        'storage_path' => 'documents/memo.pdf',
    ]);

    Storage::put('documents/memo.pdf', 'pdf');

    $this->signInAs($this->user)
        ->deleteJson("/api/documents/{$document->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('documents', ['id' => $document->id]);
    Storage::assertMissing('documents/memo.pdf');
});

it('forbids deleting another user document', function () {
    $document = Document::factory()->for(User::factory())->create();

    $this->signInAs($this->user)
        ->deleteJson("/api/documents/{$document->id}")
        ->assertForbidden();
});

it('lists documents scoped to a case', function () {
    $case = LegalCase::factory()->for($this->user)->create();
    $otherCase = LegalCase::factory()->for($this->user)->create();

    $inCase = Document::factory()->for($this->user)->create(['case_id' => $case->id]);
    $inOtherCase = Document::factory()->for($this->user)->create(['case_id' => $otherCase->id]);
    Document::factory()->for($this->user)->create(['case_id' => null]);

    $response = $this->signInAs($this->user)
        ->getJson("/api/documents?case_id={$case->id}")
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($inCase->id)
        ->and($response->json('data.0.case_id'))->toBe($case->id);
});

it('forbids listing documents for another user case', function () {
    $case = LegalCase::factory()->for(User::factory())->create();

    $this->signInAs($this->user)
        ->getJson("/api/documents?case_id={$case->id}")
        ->assertForbidden();
});

it('stores an uploaded document attached to a case', function () {
    Queue::fake();
    Storage::fake('local');

    $case = LegalCase::factory()->for($this->user)->create();

    $response = $this->signInAs($this->user)
        ->postJson('/api/documents', [
            'file' => UploadedFile::fake()->createWithContent('complaint.txt', 'Complaint notes.'),
            'case_id' => $case->id,
        ])
        ->assertCreated();

    expect($response->json('data.case_id'))->toBe($case->id);

    $this->assertDatabaseHas('documents', [
        'user_id' => $this->user->id,
        'case_id' => $case->id,
    ]);

    Queue::assertPushed(ProcessDocumentUpload::class);
});

it('forbids uploading a document into another user case', function () {
    Queue::fake();

    $case = LegalCase::factory()->for(User::factory())->create();

    $this->signInAs($this->user)
        ->postJson('/api/documents', [
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'x'),
            'case_id' => $case->id,
        ])
        ->assertForbidden();

    Queue::assertNotPushed(ProcessDocumentUpload::class);
});

it('attaches an existing document to one of the users cases', function () {
    $case = LegalCase::factory()->for($this->user)->create();
    $document = Document::factory()->for($this->user)->create(['case_id' => null]);

    $response = $this->signInAs($this->user)
        ->postJson("/api/documents/{$document->id}/attach", ['case_id' => $case->id])
        ->assertOk();

    expect($response->json('data.case_id'))->toBe($case->id);

    $this->assertDatabaseHas('documents', [
        'id' => $document->id,
        'case_id' => $case->id,
    ]);
});

it('requires a case when attaching a document', function () {
    $document = Document::factory()->for($this->user)->create();

    $this->signInAs($this->user)
        ->postJson("/api/documents/{$document->id}/attach", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('case_id');
});

it('forbids attaching another user document', function () {
    $case = LegalCase::factory()->for($this->user)->create();
    $document = Document::factory()->for(User::factory())->create();

    $this->signInAs($this->user)
        ->postJson("/api/documents/{$document->id}/attach", ['case_id' => $case->id])
        ->assertForbidden();
});

it('forbids attaching a document to another user case', function () {
    $document = Document::factory()->for($this->user)->create();
    $case = LegalCase::factory()->for(User::factory())->create();

    $this->signInAs($this->user)
        ->postJson("/api/documents/{$document->id}/attach", ['case_id' => $case->id])
        ->assertForbidden();
});

it('files a firm document into another case in the same organization', function () {
    [$organization, $lawyer] = organizationLawyer();

    $from = LegalCase::factory()->for($lawyer)->create(['organization_id' => $organization->id]);
    $to = LegalCase::factory()->for($lawyer)->create(['organization_id' => $organization->id]);

    $document = Document::factory()->for($lawyer)->create(['case_id' => $from->id]);

    $this->signInAs($lawyer)
        ->postJson("/api/documents/{$document->id}/attach", ['case_id' => $to->id])
        ->assertOk();

    $this->assertDatabaseHas('documents', ['id' => $document->id, 'case_id' => $to->id]);
});

it('files an unfiled document into one of the users organization cases', function () {
    [$organization, $lawyer] = organizationLawyer();

    $document = Document::factory()->for($lawyer)->create(['case_id' => null]);
    $case = LegalCase::factory()->for($lawyer)->create(['organization_id' => $organization->id]);

    $this->signInAs($lawyer)
        ->postJson("/api/documents/{$document->id}/attach", ['case_id' => $case->id])
        ->assertOk();
});

it('forbids filing an organization document into a personal case', function () {
    // Every individual permission check passes: the lawyer can read the
    // document and update their own personal case. What the guard refuses is
    // the join itself — moving the client's document off the firm's shelf onto
    // one the firm does not control, which the lawyer would keep after leaving.
    //
    // This is the reachable form of the boundary. A move into *another*
    // organization's case is already impossible: a user belongs to one
    // organization, so they cannot update a foreign organization's case at all
    // — the case policy refuses that before this guard is reached.
    [$organization, $lawyer] = organizationLawyer();

    $firmCase = LegalCase::factory()->for($lawyer)->create(['organization_id' => $organization->id]);
    $document = Document::factory()->for($lawyer)->create(['case_id' => $firmCase->id]);
    $personalCase = LegalCase::factory()->for($lawyer)->create(['organization_id' => null]);

    $this->signInAs($lawyer)
        ->postJson("/api/documents/{$document->id}/attach", ['case_id' => $personalCase->id])
        ->assertForbidden();

    $this->assertDatabaseHas('documents', ['id' => $document->id, 'case_id' => $firmCase->id]);
});

it('retries a failed document and re-queues ingestion', function () {
    Queue::fake();

    $document = Document::factory()->for($this->user)->failed()->create();

    $response = $this->signInAs($this->user)
        ->postJson("/api/documents/{$document->id}/retry")
        ->assertOk();

    expect($response->json('data.status'))->toBe(DocumentStatus::Queued->value)
        ->and($response->json('data.error_message'))->toBeNull();

    $this->assertDatabaseHas('documents', [
        'id' => $document->id,
        'status' => DocumentStatus::Queued->value,
        'error_message' => null,
    ]);

    Queue::assertPushed(ProcessDocumentUpload::class);
});

it('clears leftover chunks when retrying a failed document', function () {
    Queue::fake();

    $document = Document::factory()->for($this->user)->failed()->hasChunks(2)->create();

    $this->signInAs($this->user)
        ->postJson("/api/documents/{$document->id}/retry")
        ->assertOk();

    expect($document->chunks()->count())->toBe(0);
});

it('refuses to retry a document that did not fail', function () {
    Queue::fake();

    $document = Document::factory()->for($this->user)->create();

    $this->signInAs($this->user)
        ->postJson("/api/documents/{$document->id}/retry")
        ->assertStatus(422);

    Queue::assertNotPushed(ProcessDocumentUpload::class);
});

it('forbids retrying another user document', function () {
    Queue::fake();

    $document = Document::factory()->for(User::factory())->failed()->create();

    $this->signInAs($this->user)
        ->postJson("/api/documents/{$document->id}/retry")
        ->assertForbidden();

    Queue::assertNotPushed(ProcessDocumentUpload::class);
});

it('serves a viewable document inline with its content', function () {
    Storage::fake('local');

    $document = Document::factory()->for($this->user)->create([
        'storage_path' => 'documents/memo.pdf',
        'original_filename' => 'memo.pdf',
        'mime_type' => 'application/pdf',
    ]);

    Storage::put('documents/memo.pdf', '%PDF-1.4 fake content');

    $response = $this->signInAs($this->user)
        ->getJson("/api/documents/{$document->id}/file")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeaderContains('Content-Disposition', 'inline')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->streamedContent())->toBe('%PDF-1.4 fake content');
});

it('serves an encrypted document after decrypting it on the fly', function () {
    Storage::fake('local');

    $original = '%PDF-1.4 encrypted-at-rest content';

    Storage::put('documents/source.pdf', $original);

    $encryptor = app(DocumentEncryptor::class);
    $encryptor->encrypt(Storage::path('documents/source.pdf'), 'documents/memo.pdf');

    Storage::delete('documents/source.pdf');

    $document = Document::factory()->for($this->user)->create([
        'storage_path' => 'documents/memo.pdf',
        'original_filename' => 'memo.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $response = $this->signInAs($this->user)
        ->getJson("/api/documents/{$document->id}/file")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeaderContains('Content-Disposition', 'inline')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->streamedContent())->toBe($original);
});

it('serves non-viewable documents as an attachment', function () {
    Storage::fake('local');

    $document = Document::factory()->for($this->user)->create([
        'storage_path' => 'documents/contract.docx',
        'original_filename' => 'contract.docx',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ]);

    Storage::put('documents/contract.docx', 'PK fake content');

    $this->signInAs($this->user)
        ->getJson("/api/documents/{$document->id}/file")
        ->assertOk()
        ->assertHeaderContains('Content-Disposition', 'attachment');
});

it('honours an explicit disposition override', function () {
    Storage::fake('local');

    $document = Document::factory()->for($this->user)->create([
        'storage_path' => 'documents/memo.pdf',
        'original_filename' => 'memo.pdf',
        'mime_type' => 'application/pdf',
    ]);

    Storage::put('documents/memo.pdf', '%PDF-1.4 fake content');

    $this->signInAs($this->user)
        ->getJson("/api/documents/{$document->id}/file?disposition=attachment")
        ->assertOk()
        ->assertHeaderContains('Content-Disposition', 'attachment');
});

it('never serves a document under the Content-Type its uploader claimed', function () {
    Storage::fake('local');

    // `mime_type` holds whatever the browser announced at upload time. If that
    // reached the response, an uploader could pick text/html for a file on an
    // allowed extension and have the API render script in its own origin.
    $document = Document::factory()->for($this->user)->create([
        'storage_path' => 'documents/notes.txt',
        'original_filename' => 'notes.txt',
        'mime_type' => 'text/html',
    ]);

    Storage::put('documents/notes.txt', '<script>alert(document.domain)</script>');

    $this->signInAs($this->user)
        ->getJson("/api/documents/{$document->id}/file")
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('refuses to serve an unrecognised file type inline on request', function () {
    Storage::fake('local');

    $document = Document::factory()->for($this->user)->create([
        'storage_path' => 'documents/payload.bin',
        'original_filename' => 'payload.bin',
        'mime_type' => 'text/html',
    ]);

    Storage::put('documents/payload.bin', '<script>alert(1)</script>');

    // `disposition=inline` may pick between valid renderings; it may not talk
    // the API into rendering something it would otherwise hand over as a file.
    $this->signInAs($this->user)
        ->getJson("/api/documents/{$document->id}/file?disposition=inline")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/octet-stream')
        ->assertHeaderContains('Content-Disposition', 'attachment');
});

it('forbids serving another user document', function () {
    Storage::fake('local');

    $document = Document::factory()->for(User::factory())->create([
        'storage_path' => 'documents/other.pdf',
        'mime_type' => 'application/pdf',
    ]);

    Storage::put('documents/other.pdf', '%PDF-1.4 fake content');

    $this->signInAs($this->user)
        ->getJson("/api/documents/{$document->id}/file")
        ->assertForbidden();
});

it('returns 404 when the stored file is missing', function () {
    Storage::fake('local');

    $document = Document::factory()->for($this->user)->create([
        'storage_path' => 'documents/gone.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $this->signInAs($this->user)
        ->getJson("/api/documents/{$document->id}/file")
        ->assertNotFound();
});

it('requires an active subscription to serve a document file', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create([
        'storage_path' => 'documents/memo.pdf',
        'mime_type' => 'application/pdf',
    ]);

    Storage::put('documents/memo.pdf', '%PDF-1.4 fake content');

    $this->signInAs($user)
        ->getJson("/api/documents/{$document->id}/file")
        ->assertStatus(402);
});
