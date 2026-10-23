<?php

namespace App\Services\AdLibraryAudit\Concerns;

use App\Services\AdLibraryAudit\RetryableAiException;
use App\Services\AdLibraryAudit\TerminalAiException;
use Illuminate\Http\Client\Response;

trait ParsesProviderResponses
{
    private function assertSuccessful(Response $response): void
    {
        if ($response->successful()) return;
        $message = 'The AI provider rejected the request.';
        if ($response->status() === 429 || $response->serverError()) throw new RetryableAiException($message, $response->status());
        throw new TerminalAiException($message, $response->status());
    }

    private function decodeJson(string $text): array
    {
        $trimmed = trim($text);
        $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $trimmed) ?? $trimmed;
        $data = json_decode($trimmed, true);
        if (! is_array($data)) throw new TerminalAiException('The AI provider returned malformed structured data.');
        return $data;
    }
}
