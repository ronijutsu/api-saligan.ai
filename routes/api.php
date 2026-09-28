<?php

use App\Http\Controllers\Api\Admin\CrawledPageController;
use App\Http\Controllers\Api\Admin\LawyerController;
use App\Http\Controllers\Api\Admin\LawyerPayoutController;
use App\Http\Controllers\Api\Admin\LegalDocumentController;
use App\Http\Controllers\Api\Admin\LegalSourceController;
use App\Http\Controllers\Api\Admin\SystemPromptController;
use App\Http\Controllers\Api\Admin\VettingReportsController;
use App\Http\Controllers\Api\Admin\VettingSettingsController;
use App\Http\Controllers\Api\AdvisoryController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CaseAssigneeController;
use App\Http\Controllers\Api\CaseClientController;
use App\Http\Controllers\Api\CaseProgressController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DemoRequestController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\EmailPreferenceController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\GeneratedDocumentController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\KycController;
use App\Http\Controllers\Api\LabelController;
use App\Http\Controllers\Api\LawyerProfileController;
use App\Http\Controllers\Api\LawyerVettingRequestController;
use App\Http\Controllers\Api\LegalCaseController;
use App\Http\Controllers\Api\LegalPageController;
use App\Http\Controllers\Api\LetterCommentController;
use App\Http\Controllers\Api\NotarialJournalController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PaypalVettingController;
use App\Http\Controllers\Api\PaypalWebhookController;
use App\Http\Controllers\Api\PipelineController;
use App\Http\Controllers\Api\PipelineItemController;
use App\Http\Controllers\Api\PipelineStageController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\SubtaskController;
use App\Http\Controllers\Api\TaskActivityController;
use App\Http\Controllers\Api\TaskAttachmentController;
use App\Http\Controllers\Api\TaskCommentController;
use App\Http\Controllers\Api\TemplateController;
use App\Http\Controllers\Api\TermsController;
use App\Http\Controllers\Api\TextRewriteController;
use App\Http\Controllers\Api\TodoController;
use App\Http\Controllers\Api\TopUpController;
use App\Http\Controllers\Api\TopUpWebhookController;
use App\Http\Controllers\Api\TourController;
use App\Http\Controllers\Api\TrialCodeController;
use App\Http\Controllers\Api\VettingRequestController;
use App\Http\Controllers\Api\VettingWebhookController;
use App\Http\Controllers\Api\WebPageController;
use Illuminate\Support\Facades\Route;

Route::post('/subscriptions/webhook', [SubscriptionController::class, 'webhook']);
Route::post('/subscriptions/webhook/lemonsqueezy', [SubscriptionController::class, 'lemonsqueezyWebhook']);

Route::post('/paypal/webhook', [PaypalWebhookController::class, 'handle']);
Route::get('/paypal/vetting/return', [PaypalVettingController::class, 'return']);
Route::get('/paypal/vetting/cancel', [PaypalVettingController::class, 'cancel']);

Route::post('/vetting/webhook', [VettingWebhookController::class, 'payments']);

Route::post('/paymongo/topup/webhook', [TopUpWebhookController::class, 'handle']);

Route::get('/plans', [PlanController::class, 'index']);

Route::post('/demo-requests', [DemoRequestController::class, 'store'])
    ->middleware('throttle:demo-request');

// Looked up by the login screen (pre-auth) to greet a returning user with
// their last-used time and to refuse an unregistered email before the
// password step. Both answers leak whether an email has an account, so the
// route is throttled to keep enumeration slow.
Route::get('/auth/last-used', [AuthController::class, 'lastUsed'])
    ->middleware('throttle:last-used-lookup');

// Creates the account and emails the confirmation link through Laravel's
// mailer. Answered identically for new and existing addresses so it cannot be
// used to probe registrations; throttled in case of abuse.
Route::post('/auth/register', [AuthController::class, 'register'])
    ->middleware('throttle:registration');

// The published Terms of Service / Privacy Policy is public content; the
// /legal/terms page links it from the register form, which logged-out
// visitors can open. Acceptance and status are per-user and stay protected.
Route::get('/terms/document', [TermsController::class, 'document']);

// An organization's logo, read by an `<img>` tag that cannot carry the bearer
// token every other route expects. The signature on the URL stands in for it,
// and is only ever handed out inside a payload a member had the right to read.
Route::get('/organizations/{organization}/logo', [OrganizationController::class, 'logo'])
    ->middleware('signed')
    ->name('organizations.logo');

// The unsubscribe link in lifecycle emails, and the one-click POST that mail
// clients send (RFC 8058). Recipients are rarely logged in when they click, so
// the signature on the URL is the authorization, as with the logo route.
Route::match(['get', 'post'], '/email/unsubscribe/{user}', [EmailPreferenceController::class, 'unsubscribe'])
    ->middleware('signed')
    ->name('email.unsubscribe');

// The OAuth landing for add-on integrations. The provider returns the browser
// here with a code and an encrypted, expiry-stamped state; no bearer token
// rides along, so the route is public and the state is the authentication.
Route::get('/integrations/callback', [IntegrationController::class, 'callback']);

// Push notifications from the add-on providers. Public by necessity — Google
// and Microsoft cannot carry a bearer token — and authenticated by the
// channel/subscription metadata only the registration stored.
Route::post('/integrations/webhooks/google', [IntegrationController::class, 'googleWebhook']);
Route::post('/integrations/webhooks/microsoft', [IntegrationController::class, 'microsoftWebhook']);

Route::middleware(['auth:supabase', 'track_last_used', 'not_suspended'])->group(function (): void {
    // Exempt from `not_suspended`: the client has to be able to read back the
    // suspended status it is about to act on, and the member has to be able to
    // walk away from the organization that suspended them. Everything else in
    // this group is closed to them.
    Route::get('/user', [AuthController::class, 'user'])
        ->withoutMiddleware('not_suspended')
        ->name('user');

    Route::post('/organizations/leave', [OrganizationController::class, 'leave'])
        ->withoutMiddleware('not_suspended');

    Route::get('/terms/status', [TermsController::class, 'status']);
    Route::post('/terms/accept', [TermsController::class, 'accept']);

    Route::get('/kyc', [KycController::class, 'show']);
    Route::put('/kyc', [KycController::class, 'store']);
    Route::delete('/kyc', [KycController::class, 'destroy']);

    Route::post('/tour/complete', [TourController::class, 'complete']);

    Route::get('/legal-pages/resolve', [LegalPageController::class, 'resolve']);
    Route::get('/legal-pages/{crawledPage}', [LegalPageController::class, 'show']);

    // The in-app notification feed lives outside the active_subscription group
    // so the navbar bell works for trial and suspended users too.
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::patch('/notifications/{notification}', [NotificationController::class, 'markRead']);
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);

    // Trial redemption sits outside the active_subscription group: the whole
    // point is to be reachable by an account that has no subscription yet.
    Route::get('/trial/code', [TrialCodeController::class, 'show']);
    Route::post('/trial/redeem', [TrialCodeController::class, 'store'])
        ->middleware('throttle:6,1');

    Route::get('/subscription', [SubscriptionController::class, 'show']);
    Route::post('/subscription/trial', [SubscriptionController::class, 'startTrial'])
        ->middleware('throttle:6,1');
    Route::post('/subscription', [SubscriptionController::class, 'store']);
    Route::post('/subscription/change-plan', [SubscriptionController::class, 'changePlan']);
    Route::post('/subscription/change-plan/cancel', [SubscriptionController::class, 'cancelPlanChange']);
    Route::post('/subscription/cancel', [SubscriptionController::class, 'cancel']);
    Route::post('/subscription/seats', [SubscriptionController::class, 'addSeats']);
    Route::delete('/subscription/seats', [SubscriptionController::class, 'removeSeats']);

    // Prepaid extra AI usage (ADR-010). The response is the meter's companion,
    // so it lives with the subscription routes rather than behind
    // active_subscription: an account whose allowance is exhausted still needs
    // to reach the control that unblocks it.
    Route::get('/billing/top-ups', [TopUpController::class, 'show']);
    Route::get('/billing/top-ups/history', [TopUpController::class, 'history']);
    Route::patch('/billing/top-ups/settings', [TopUpController::class, 'updateSettings']);
    Route::post('/billing/top-ups', [TopUpController::class, 'store'])
        ->middleware('throttle:10,1');

    // The add-ons catalogue is a discovery surface, so it stays readable on
    // every plan — a locked card still has to render. Disconnecting is allowed
    // here too: cutting a cord is a safety act, not a feature a plan gates.
    Route::get('/integrations', [IntegrationController::class, 'index']);
    Route::delete('/integrations/{provider}', [IntegrationController::class, 'disconnect']);

    Route::get('/organizations', [OrganizationController::class, 'show']);
    Route::post('/organizations', [OrganizationController::class, 'store']);
    Route::patch('/organizations', [OrganizationController::class, 'update']);
    Route::post('/organizations/logo', [OrganizationController::class, 'storeLogo']);
    Route::delete('/organizations/logo', [OrganizationController::class, 'destroyLogo']);
    Route::get('/organizations/members', [OrganizationController::class, 'members']);
    Route::delete('/organizations/members/{member}', [OrganizationController::class, 'removeMember']);
    Route::post('/organizations/members/{member}/suspend', [OrganizationController::class, 'suspendMember']);
    Route::post('/organizations/members/{member}/resume', [OrganizationController::class, 'resumeMember']);

    Route::get('/organizations/invitations', [OrganizationController::class, 'indexInvitations']);
    Route::post('/organizations/invitations', [OrganizationController::class, 'storeInvitation']);
    Route::post('/organizations/invitations/accept', [OrganizationController::class, 'acceptInvitationByToken']);
    Route::delete('/organizations/invitations/{invitation}', [OrganizationController::class, 'revokeInvitation']);
    Route::get('/invitations/pending', [OrganizationController::class, 'pendingInvitations']);
    Route::post('/invitations/{invitation}/accept', [OrganizationController::class, 'acceptInvitation']);

    // The lawyer side of the platform lives outside active_subscription: a
    // lawyer serves requests on a verified profile, not on a plan.
    Route::get('/lawyer/profile', [LawyerProfileController::class, 'show']);
    Route::post('/lawyer/profile', [LawyerProfileController::class, 'store']);
    Route::patch('/lawyer/profile', [LawyerProfileController::class, 'update']);
    Route::patch('/lawyer/profile/availability', [LawyerProfileController::class, 'availability']);

    Route::get('/lawyer/vetting-requests', [LawyerVettingRequestController::class, 'index']);
    Route::get('/lawyer/vetting-requests/{vettingRequest}', [LawyerVettingRequestController::class, 'show']);
    Route::post('/lawyer/vetting-requests/{vettingRequest}/accept', [LawyerVettingRequestController::class, 'accept']);
    Route::post('/lawyer/vetting-requests/{vettingRequest}/decline', [LawyerVettingRequestController::class, 'decline']);
    Route::patch('/lawyer/vetting-requests/{vettingRequest}/status', [LawyerVettingRequestController::class, 'markStatus']);
    Route::post('/lawyer/vetting-requests/{vettingRequest}/schedule', [LawyerVettingRequestController::class, 'schedule']);
    Route::post('/lawyer/vetting-requests/{vettingRequest}/notarize', [LawyerVettingRequestController::class, 'notarize']);

    // The notarial journal is the lawyer's own legal register; admins may read
    // any lawyer's entries for audit.
    Route::get('/lawyer/journal', [NotarialJournalController::class, 'index']);

    // The clarification thread and the full document are shared between the
    // submitter and the lawyer who holds the request, so both are reachable
    // without an active plan.
    Route::get('/vetting-requests/{vettingRequest}/messages', [VettingRequestController::class, 'messages']);
    Route::post('/vetting-requests/{vettingRequest}/messages', [VettingRequestController::class, 'sendMessage']);
    Route::get('/vetting-requests/{vettingRequest}/file', [VettingRequestController::class, 'file']);

    Route::middleware(['active_subscription', 'terms.accepted'])->group(function (): void {
        // Add-on integrations. Every mutating call re-checks the plan's
        // `integrations` capability server-side; the locked cards are an
        // upsell, not the gate.
        Route::post('/integrations/{provider}/connect', [IntegrationController::class, 'connect']);
        Route::post('/integrations/{provider}/capabilities/{capability}', [IntegrationController::class, 'toggleCapability']);
        Route::post('/integrations/{provider}/sync', [IntegrationController::class, 'sync']);
        Route::post('/integrations/{provider}/reauthorize', [IntegrationController::class, 'reauthorize']);

        // Firm-level add-on management: who has connected what, org-wide
        // capability policies, the per-seat vs firm-wide switch, and the audit
        // trail. Organization managers only.
        Route::get('/organizations/integrations', [IntegrationController::class, 'admin']);
        Route::put('/organizations/integrations/policies', [IntegrationController::class, 'updatePolicies']);
        Route::put('/organizations/integrations/connection-mode', [IntegrationController::class, 'updateConnectionMode']);
        Route::get('/organizations/integrations/audit-logs', [IntegrationController::class, 'auditLogs']);

        Route::post('/labels/reorder', [LabelController::class, 'reorder']);
        Route::apiResource('labels', LabelController::class)->only(['index', 'store', 'update', 'destroy']);

        Route::apiResource('documents', DocumentController::class);
        Route::post('/documents/{document}/attach', [DocumentController::class, 'attach']);
        Route::post('/documents/{document}/retry', [DocumentController::class, 'retry']);
        Route::get('/documents/{document}/file', [DocumentController::class, 'file']);
        Route::get('/documents/{document}/content', [DocumentController::class, 'content']);

        // Fetches an arbitrary public page and pays for a digest of it, so it
        // is bounded per user.
        Route::post('/web-pages/read', [WebPageController::class, 'read'])->middleware('throttle:30,1');

        Route::get('/generated-documents', [GeneratedDocumentController::class, 'index']);
        Route::get('/generated-documents/{message}', [GeneratedDocumentController::class, 'show']);
        Route::patch('/messages/{message}/letter-draft', [GeneratedDocumentController::class, 'saveLetterDraft']);

        Route::apiResource('conversations', ConversationController::class);
        Route::post('/conversations/{conversation}/messages', [ChatController::class, 'store']);
        Route::post('/conversations/{conversation}/pin', [ConversationController::class, 'pin']);
        Route::post('/conversations/{conversation}/unpin', [ConversationController::class, 'unpin']);

        Route::post('/messages/{message}/export/word', [ExportController::class, 'word']);
        Route::post('/messages/{message}/export/pdf', [ExportController::class, 'pdf']);
        Route::post('/messages/{message}/feedback', [FeedbackController::class, 'store']);
        Route::delete('/messages/{message}/feedback', [FeedbackController::class, 'destroy']);

        Route::post('/text/rewrite', [TextRewriteController::class, 'store']);

        Route::apiResource('todos', TodoController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::post('/todos/reorder', [TodoController::class, 'reorder']);

        Route::apiResource('todos.subtasks', SubtaskController::class)->except(['show']);
        Route::apiResource('todos.comments', TaskCommentController::class)->except(['show', 'update']);
        Route::apiResource('messages.comments', LetterCommentController::class)->except(['show', 'update']);
        Route::get('/todos/{todo}/activities', [TaskActivityController::class, 'index']);
        Route::apiResource('todos.attachments', TaskAttachmentController::class)->only(['index', 'store', 'destroy']);

        Route::apiResource('advisories', AdvisoryController::class)->only(['index', 'update', 'destroy']);

        Route::get('/templates', [TemplateController::class, 'index']);
        Route::post('/templates', [TemplateController::class, 'store']);
        Route::post('/templates/{template}/fill', [TemplateController::class, 'fill']);
        Route::delete('/templates/{template}', [TemplateController::class, 'destroy']);

        Route::apiResource('cases', LegalCaseController::class);
        Route::apiResource('clients', ClientController::class)->only(['index', 'show']);
        Route::get('/clients/{client}/cases', [CaseClientController::class, 'clientIndex']);
        Route::get('/cases/{case}/clients', [CaseClientController::class, 'caseIndex']);
        Route::middleware(['crm.idempotency', 'throttle:crm-mutation'])->group(function (): void {
            Route::post('/clients', [ClientController::class, 'store']);
            Route::patch('/clients/{client}', [ClientController::class, 'update']);
            Route::delete('/clients/{client}', [ClientController::class, 'destroy']);
            Route::post('/clients/{client}/restore', [ClientController::class, 'restore']);
            Route::post('/clients/{client}/cases', [CaseClientController::class, 'attachToClient']);
            Route::delete('/clients/{client}/cases/{case}', [CaseClientController::class, 'detachFromClient']);
            Route::post('/cases/{case}/clients', [CaseClientController::class, 'attachToCase']);
            Route::delete('/cases/{case}/clients/{client}', [CaseClientController::class, 'detachFromCase']);
        });

        Route::get('/pipeline-templates', [PipelineController::class, 'templates']);
        Route::get('/pipelines', [PipelineController::class, 'index']);
        Route::get('/pipelines/{pipeline}', [PipelineController::class, 'show']);

        Route::get('/pipeline-items', [PipelineItemController::class, 'index']);
        Route::get('/pipeline-items/{item}', [PipelineItemController::class, 'show']);
        Route::get('/pipeline-items/{item}/history', [PipelineItemController::class, 'history']);

        Route::middleware('crm.idempotency')->group(function (): void {
            Route::post('/pipelines', [PipelineController::class, 'store']);
            Route::post('/pipelines/provision', [PipelineController::class, 'provision'])
                ->middleware('throttle:crm-mutation');
            Route::patch('/pipelines/{pipeline}', [PipelineController::class, 'update']);
            Route::delete('/pipelines/{pipeline}', [PipelineController::class, 'destroy']);
            Route::post('/pipelines/{pipeline}/restore', [PipelineController::class, 'restore']);
            Route::post('/pipelines/{pipeline}/stages', [PipelineStageController::class, 'store']);
            Route::post('/pipelines/{pipeline}/stages/reorder', [PipelineStageController::class, 'reorder']);
            Route::patch('/pipelines/{pipeline}/stages/{stage}', [PipelineStageController::class, 'update']);
            Route::delete('/pipelines/{pipeline}/stages/{stage}', [PipelineStageController::class, 'destroy']);
            Route::post('/pipelines/{pipeline}/stages/{stage}/restore', [PipelineStageController::class, 'restore']);

            Route::post('/pipeline-items', [PipelineItemController::class, 'store']);
            Route::patch('/pipeline-items/{item}', [PipelineItemController::class, 'update']);
            Route::delete('/pipeline-items/{item}', [PipelineItemController::class, 'destroy']);
            Route::post('/pipeline-items/{item}/restore', [PipelineItemController::class, 'restore']);
            Route::patch('/pipeline-items/{item}/stage', [PipelineItemController::class, 'move']);
        });

        Route::get('/cases/{case}/progress', [CaseProgressController::class, 'show']);
        Route::patch('/cases/{case}/status', [LegalCaseController::class, 'updateStatus']);
        Route::post('/cases/{case}/conversations', [LegalCaseController::class, 'storeConversation']);
        Route::post('/cases/{case}/duplicate', [LegalCaseController::class, 'duplicate']);
        Route::post('/cases/{case}/restore', [LegalCaseController::class, 'restore']);
        Route::delete('/cases/{case}/force', [LegalCaseController::class, 'forceDestroy']);

        // Who is working a case. `candidates` is separate from `index` because
        // it answers a different question — who could be added — and only
        // someone who may manage the case is allowed to ask it.
        Route::get('/cases/{case}/assignees', [CaseAssigneeController::class, 'index']);
        Route::get('/cases/{case}/assignees/candidates', [CaseAssigneeController::class, 'candidates']);
        Route::post('/cases/{case}/assignees', [CaseAssigneeController::class, 'store']);
        Route::delete('/cases/{case}/assignees/{user}', [CaseAssigneeController::class, 'destroy']);

        // The submitter-facing vetting flow. Creating a request requires an
        // active plan; the summary/document of a created request stays
        // readable here, and cancellation only works while unassigned.
        Route::post('/vetting-requests', [VettingRequestController::class, 'store']);
        Route::get('/vetting-requests', [VettingRequestController::class, 'index']);
        Route::get('/vetting-requests/{vettingRequest}', [VettingRequestController::class, 'show']);
        Route::post('/vetting-requests/{vettingRequest}/cancel', [VettingRequestController::class, 'cancel']);
        Route::post('/vetting-requests/{vettingRequest}/retry', [VettingRequestController::class, 'retry']);

        // One aggregated read for the post-login Dashboard (see planning-frontend).
        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
    });

    Route::prefix('admin')->middleware('is_admin')->group(function (): void {
        Route::apiResource('legal-sources', LegalSourceController::class)->only(['index', 'store', 'destroy']);
        Route::post('/legal-sources/{legalSource}/crawl-now', [LegalSourceController::class, 'crawlNow']);
        Route::get('/crawled-pages', [CrawledPageController::class, 'index']);
        Route::get('/legal-documents', [LegalDocumentController::class, 'index']);
        Route::post('/legal-documents', [LegalDocumentController::class, 'store']);
        Route::get('/legal-documents/{crawledPage}/file', [LegalDocumentController::class, 'file']);
        Route::delete('/legal-documents/{crawledPage}', [LegalDocumentController::class, 'destroy']);
        Route::get('/system-prompts', [SystemPromptController::class, 'index']);
        Route::post('/system-prompts', [SystemPromptController::class, 'store']);
        Route::post('/system-prompts/{systemPrompt}/activate', [SystemPromptController::class, 'activate']);

        // The lawyer verification queue and the vetting marketplace controls.
        Route::get('/lawyers', [LawyerController::class, 'index']);
        Route::get('/lawyers/{lawyerProfile}', [LawyerController::class, 'show']);
        Route::post('/lawyers/{lawyerProfile}/approve', [LawyerController::class, 'approve']);
        Route::post('/lawyers/{lawyerProfile}/reject', [LawyerController::class, 'reject']);
        Route::post('/lawyers/{lawyerProfile}/suspend', [LawyerController::class, 'suspend']);
        Route::post('/lawyers/{lawyerProfile}/revoke', [LawyerController::class, 'revoke']);
        Route::post('/lawyers/{lawyerProfile}/reopen', [LawyerController::class, 'reopen']);
        Route::get('/lawyers/{lawyerProfile}/document/{kind}', [LawyerController::class, 'document']);

        Route::get('/vetting/settings', [VettingSettingsController::class, 'show']);
        Route::put('/vetting/settings', [VettingSettingsController::class, 'update']);

        Route::get('/vetting/reports/summary', [VettingReportsController::class, 'summary']);
        Route::get('/vetting/reports/lawyers', [VettingReportsController::class, 'lawyers']);

        Route::get('/lawyer-payouts', [LawyerPayoutController::class, 'index']);
        Route::post('/lawyer-payouts/{lawyerPayout}/mark-paid', [LawyerPayoutController::class, 'markPaid']);
    });
});
