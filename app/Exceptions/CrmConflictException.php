<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

class CrmConflictException extends RuntimeException
{
    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'errors' => $this->errors,
            'request_id' => $request->header('X-Request-Id') ?: (string) Str::uuid(),
        ], $this->status);
    }
}
