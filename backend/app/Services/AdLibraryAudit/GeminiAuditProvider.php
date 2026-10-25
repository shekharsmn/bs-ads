<?php

namespace App\Services\AdLibraryAudit;

use App\Services\AdLibraryAudit\Concerns\ParsesProviderResponses;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class GeminiAuditProvider implements AdLibraryAuditAiProvider, UrlContextAuditProvider
{
    use ParsesProviderResponses;

    public function generateJson(string $model, string $system, string $prompt, array $schema, array $media = []): AiResponse
    {
        $parts = [['text' => $prompt]];
        foreach ($media as $item) {
            $parts[] = ['inlineData' => ['mimeType' => $item['mime'], 'data' => base64_encode($item['data'])]];
        }

        return $this->generate($model, $system, $parts, $schema);
    }

    public function generateJsonFromUrl(string $model, string $system, string $prompt, string $url, array $schema): AiResponse
    {
        return $this->generate(
            $model,
            $system,
            [['text' => $prompt."\n\nWebsite URL: ".$url]],
            $schema,
            [['url_context' => new \stdClass]],
        );
    }

    private function generate(string $model, string $system, array $parts, array $schema, array $tools = []): AiResponse
    {
        $base = rtrim(config('ad_library_audit.providers.gemini.base_url'), '/');
        $key = rawurlencode((string) config('ad_library_audit.providers.gemini.api_key'));
        $payload = [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => ['responseMimeType' => 'application/json', 'responseSchema' => $this->geminiSchema($schema), 'temperature' => 0.2],
        ];
        if ($tools !== []) {
            $payload['tools'] = $tools;
        }
        try {
            $response = Http::timeout(180)->post("{$base}/models/{$model}:generateContent?key={$key}", $payload);
        } catch (ConnectionException $e) {
            throw new RetryableAiException('The AI provider could not be reached.', 0, $e);
        }
        $this->assertSuccessful($response);
        $body = $response->json();
        if ($tools !== []) {
            $retrievals = data_get($body, 'candidates.0.urlContextMetadata.urlMetadata', []);
            $retrieved = collect(is_array($retrievals) ? $retrievals : [])->contains(
                fn ($item) => data_get($item, 'urlRetrievalStatus') === 'URL_RETRIEVAL_STATUS_SUCCESS'
            );
            if (! $retrieved) {
                throw new TerminalAiException('The AI provider could not retrieve the website URL.');
            }
        }
        $text = data_get($body, 'candidates.0.content.parts.0.text', '');

        return new AiResponse($this->decodeJson($text), (int) data_get($body, 'usageMetadata.promptTokenCount', 0), (int) data_get($body, 'usageMetadata.candidatesTokenCount', 0));
    }

    private function geminiSchema(array $schema): array
    {
        unset($schema['additionalProperties']);

        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                $schema[$key] = $this->geminiSchema($value);
            }
        }

        return $schema;
    }
}
