<?php

namespace App\Services\Ai;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class PythonAiClient
{
    /** @param array<string, mixed> $payload */
    public function call(string $path, array $payload): array
    {
        return $this->request()
            ->post($path, $payload)
            ->throw()
            ->json();
    }

    /** @return array<string, mixed> */
    public function get(string $path): array
    {
        return $this->request()
            ->get($path)
            ->throw()
            ->json();
    }

    /**
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function embeddings(array $texts): array
    {
        $vectors = [];

        foreach (array_chunk(array_values($texts), 128) as $batch) {
            $response = $this->call('/embeddings', ['texts' => $batch]);
            $embeddings = $response['embeddings'] ?? [];

            if (! is_array($embeddings) || count($embeddings) !== count($batch)) {
                throw new \RuntimeException('Python returned a mismatched embedding count.');
            }

            foreach ($embeddings as $vector) {
                $vectors[] = $vector;
            }
        }

        return $vectors;
    }

    /** @return array<string, mixed> */
    public function ocr(string $path, string $mimeType): array
    {
        $file = fopen($path, 'r');

        if ($file === false) {
            throw new \RuntimeException("Could not open {$path} for OCR.");
        }

        try {
            return $this->request()
                ->attach('file', $file, basename($path), ['Content-Type' => $mimeType])
                ->post('/documents/ocr')
                ->throw()
                ->json();
        } finally {
            fclose($file);
        }
    }

    /**
     * Open Python's streaming response without consuming its body.
     *
     * @param  array<string, mixed>  $payload
     */
    public function stream(string $path, array $payload): Response
    {
        return $this->request()
            ->withOptions(['stream' => true])
            ->post($path, $payload)
            ->throw();
    }

    /**
     * @param  array<int, string>  $attachmentIds
     */
    public function streamChat(
        string $conversationId,
        string $message,
        array $attachmentIds,
        bool $isDraftingRequest,
        bool $isIntakeSubmission,
        ?string $reservationId = null,
    ): Response {
        return $this->stream("/chat/{$conversationId}/stream", array_filter([
            'message' => $message,
            'attachment_ids' => $attachmentIds,
            'is_drafting_request' => $isDraftingRequest,
            'is_intake_submission' => $isIntakeSubmission,
            'reservation_id' => $reservationId,
        ], fn (mixed $value): bool => $value !== null));
    }

    /** @return Generator<int, string> */
    public function body(Response $response): Generator
    {
        $body = $response->toPsrResponse()->getBody();

        try {
            while (! $body->eof()) {
                $chunk = $body->read(8192);

                if ($chunk !== '') {
                    yield $chunk;
                }
            }
        } finally {
            $response->close();
        }
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl((string) config('saligan.ai_provider.url'))
            ->acceptJson()
            ->withToken((string) config('saligan.ai_provider.internal_secret'))
            ->connectTimeout((int) config('saligan.ai_provider.connect_timeout', 5))
            ->timeout((int) config('saligan.ai_provider.timeout', 300));
    }
}
