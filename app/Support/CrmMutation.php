<?php

namespace App\Support;

use App\Exceptions\CrmConflictException;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CrmMutation
{
    public static function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null) {
            return null;
        }

        if ($key === '' || mb_strlen($key) > 128) {
            throw new CrmConflictException(
                'invalid_idempotency_key',
                'Idempotency-Key must be between 1 and 128 characters.',
                422,
                ['Idempotency-Key' => ['The idempotency key is invalid.']],
            );
        }

        return $key;
    }

    public static function scopeKey(User $user): string
    {
        $scope = $user->hasActiveMembership()
            ? 'organization:'.($user->organization_id ?? 'none')
            : 'solo';

        return 'user:'.$user->getAuthIdentifier().'|'.$scope;
    }

    public static function fingerprint(Request $request): string
    {
        $payload = [
            'method' => $request->method(),
            'path' => $request->path(),
            'query' => self::canonicalize($request->query()),
            'body' => self::canonicalize($request->all()),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public static function idempotencyExpiresAt(): Carbon
    {
        return now()->addHours(max(1, (int) config('crm.idempotency_retention_hours', 24)));
    }

    public static function assertIfMatch(Request $request, Model $model): void
    {
        self::assertIfMatchValue($request->header('If-Match'), $model);
    }

    public static function assertIfMatchValue(?string $provided, Model $model): void
    {
        if ($provided === null || $provided === '*') {
            return;
        }

        if ($provided !== self::etag($model)) {
            throw new CrmConflictException(
                'stale_resource',
                'This resource changed. Refresh before saving it.',
            );
        }
    }

    public static function etag(Model $model): string
    {
        // The local PostgreSQL schema stores timestamps at second precision,
        // so updated_at alone can remain unchanged across two quick writes.
        // Hashing the persisted attributes gives stage moves and metadata edits
        // a reliable version without adding a second version column.
        $attributes = $model->getRawOriginal();
        ksort($attributes);

        return '"'.hash('sha256', $model->getTable().'|'.$model->getKey().'|'.serialize($attributes)).'"';
    }

    public static function withEtag(JsonResponse $response, Model $model): JsonResponse
    {
        return $response->header('ETag', self::etag($model->fresh() ?? $model));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        $keys = array_keys($value);
        sort($keys, SORT_STRING);

        $canonical = [];

        foreach ($keys as $key) {
            $canonical[$key] = self::canonicalize($value[$key]);
        }

        return $canonical;
    }
}
