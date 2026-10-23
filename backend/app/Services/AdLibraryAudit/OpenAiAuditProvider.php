<?php

namespace App\Services\AdLibraryAudit;

use App\Services\AdLibraryAudit\Concerns\ParsesProviderResponses;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OpenAiAuditProvider implements AdLibraryAuditAiProvider
{
    use ParsesProviderResponses;

    public function generateJson(string $model, string $system, string $prompt, array $schema, array $media = []): AiResponse
    {
        $content = [['type' => 'text', 'text' => $prompt]];
        foreach ($media as $item) {
            if (! str_starts_with($item['mime'], 'image/')) continue;
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => "data:{$item['mime']};base64,".base64_encode($item['data'])]];
        }
        $base = rtrim(config('ad_library_audit.providers.openai.base_url'), '/');
        try {
            $response = Http::withToken(config('ad_library_audit.providers.openai.api_key'))->timeout(180)->post("{$base}/chat/completions", [
                'model' => $model,
                'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $content]],
                'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'ad_library_audit', 'strict' => true, 'schema' => $schema]],
                'temperature' => 0.2,
            ]);
        } catch (ConnectionException $e) {
            throw new RetryableAiException('The AI provider could not be reached.', 0, $e);
        }
        $this->assertSuccessful($response);
        $body = $response->json();
        return new AiResponse($this->decodeJson((string) data_get($body, 'choices.0.message.content', '')), (int) data_get($body, 'usage.prompt_tokens', 0), (int) data_get($body, 'usage.completion_tokens', 0));
    }
}
