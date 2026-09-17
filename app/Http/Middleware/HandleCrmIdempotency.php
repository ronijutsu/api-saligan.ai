<?php

namespace App\Http\Middleware;

use App\Exceptions\CrmConflictException;
use App\Models\CrmIdempotencyRecord;
use App\Support\CrmMutation;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class HandleCrmIdempotency
{
    public function __construct(private readonly ExceptionHandler $exceptions) {}

    /**
     * Handle an idempotent CRM mutation.
     *
     * The command and its response are committed in one database transaction.
     * `insertOrIgnore` lets a concurrent retry wait on the unique key and then
     * replay the committed response instead of running the command twice.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $idempotencyKey = CrmMutation::idempotencyKey($request);

        if ($idempotencyKey === null || $request->user() === null) {
            return $next($request);
        }

        $scopeKey = CrmMutation::scopeKey($request->user());
        $fingerprint = CrmMutation::fingerprint($request);

        return DB::transaction(function () use ($request, $next, $idempotencyKey, $scopeKey, $fingerprint): Response {
            $record = $this->findOrCreate($scopeKey, $idempotencyKey, $fingerprint);

            if ($record->request_fingerprint !== $fingerprint) {
                throw new CrmConflictException(
                    'idempotency_conflict',
                    'This idempotency key was already used for a different request.',
                );
            }

            if ($record->completed_at !== null) {
                return $record->replay();
            }

            try {
                $response = $next($request);
            } catch (Throwable $exception) {
                $this->exceptions->report($exception);
                $response = $this->exceptions->render($request, $exception);

                if ($response->getStatusCode() >= 500) {
                    $record->delete();

                    return $response;
                }

                $this->complete($record, $response);

                return $response;
            }

            if ($response->getStatusCode() >= 500) {
                $record->delete();

                return $response;
            }

            $this->complete($record, $response);

            return $response;
        });
    }

    private function findOrCreate(string $scopeKey, string $idempotencyKey, string $fingerprint): CrmIdempotencyRecord
    {
        $record = $this->query($scopeKey, $idempotencyKey)->lockForUpdate()->first();

        if ($record !== null && $record->expires_at->isPast()) {
            $record->delete();
            $record = null;
        }

        if ($record === null) {
            DB::table('crm_idempotency_records')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'scope_key' => $scopeKey,
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'expires_at' => CrmMutation::idempotencyExpiresAt(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $record = $this->query($scopeKey, $idempotencyKey)->lockForUpdate()->firstOrFail();
        }

        return $record;
    }

    private function complete(CrmIdempotencyRecord $record, Response $response): void
    {
        $content = $response->getContent();

        if ($content === false) {
            $record->delete();

            return;
        }

        $record->forceFill([
            'response_status' => $response->getStatusCode(),
            'response_headers' => $this->replayableHeaders($response),
            'response_body' => Crypt::encryptString($content),
            'completed_at' => now(),
        ])->save();
    }

    /**
     * @return Builder<CrmIdempotencyRecord>
     */
    private function query(string $scopeKey, string $idempotencyKey): Builder
    {
        return CrmIdempotencyRecord::query()
            ->where('scope_key', $scopeKey)
            ->where('idempotency_key', $idempotencyKey);
    }

    /**
     * Keep replay metadata limited to headers that affect the API contract.
     * Cookies and transport headers must never be persisted for replay.
     *
     * @return array<string, string>
     */
    private function replayableHeaders(Response $response): array
    {
        $headers = [];

        foreach (['Content-Type', 'ETag', 'Location'] as $name) {
            if ($response->headers->has($name)) {
                $headers[$name] = (string) $response->headers->get($name);
            }
        }

        return $headers;
    }
}
