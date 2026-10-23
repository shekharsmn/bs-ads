<?php

namespace App\Services\AdLibraryAudit;

use App\Services\AdLibraryAudit\Concerns\ParsesProviderResponses;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class GeminiAuditProvider implements AdLibraryAuditAiProvider
{
    use ParsesProviderResponses;

    public function generateJson(string $model, string $system, string $prompt, array $schema, array $media = []): AiResponse
    {
        $parts = [['text' => $prompt]];
        foreach ($media as $item) {
            $parts[] = ['inlineData' => ['mimeType' => $item['mime'], 'data' => base64_encode($item['data'])]];
        }
        $base = rtrim(config('ad_library_audit.providers.gemini.base_url'), '/');
        $key = rawurlencode((string) config('ad_library_audit.providers.gemini.api_key'));
        try {
            $response = Http::timeout(180)->post("{$base}/models/{$model}:generateContent?key={$key}", [
                'system_instruction' => ['parts' => [['text' => $system]]],
                'contents' => [['role' => 'user', 'parts' => $parts]],
                'generationConfig' => ['responseMimeType' => 'application/json', 'responseSchema' => $this->geminiSchema($schema), 'temperature' => 0.2],
            ]);
        } catch (ConnectionException $e) {
            throw new RetryableAiException('The AI provider could not be reached.', 0, $e);
        }
        $this->assertSuccessful($response);
        $body = $response->json();
        $text = data_get($body, 'candidates.0.content.parts.0.text', '');
        return new AiResponse($this->decodeJson($text), (int) data_get($body, 'usageMetadata.promptTokenCount', 0), (int) data_get($body, 'usageMetadata.candidatesTokenCount', 0));
    }

    private function geminiSchema(array $schema): array
    {
        unset($schema['additionalProperties']);

        foreach ($schema as $key => $value) {
            if (is_array($value)) $schema[$key] = $this->geminiSchema($value);
        }

        return $schema;
    }
}
