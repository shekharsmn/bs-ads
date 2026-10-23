<?php

namespace App\Services\AdLibraryAudit;

use App\Services\AdLibraryAudit\Concerns\ParsesProviderResponses;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class AnthropicAuditProvider implements AdLibraryAuditAiProvider
{
    use ParsesProviderResponses;

    public function generateJson(string $model, string $system, string $prompt, array $schema, array $media = []): AiResponse
    {
        $content = [['type' => 'text', 'text' => $prompt]];
        foreach ($media as $item) {
            if (! str_starts_with($item['mime'], 'image/')) continue;
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $item['mime'], 'data' => base64_encode($item['data'])]];
        }
        $base = rtrim(config('ad_library_audit.providers.anthropic.base_url'), '/');
        try {
            $response = Http::withHeaders([
                'x-api-key' => config('ad_library_audit.providers.anthropic.api_key'),
                'anthropic-version' => '2023-06-01',
            ])->timeout(180)->post("{$base}/messages", [
                'model' => $model, 'max_tokens' => 4096, 'system' => $system,
                'messages' => [['role' => 'user', 'content' => $content]],
                'tools' => [['name' => 'submit_audit', 'description' => 'Submit the structured audit result.', 'input_schema' => $schema]],
                'tool_choice' => ['type' => 'tool', 'name' => 'submit_audit'],
            ]);
        } catch (ConnectionException $e) {
            throw new RetryableAiException('The AI provider could not be reached.', 0, $e);
        }
        $this->assertSuccessful($response);
        $body = $response->json();
        $tool = collect($body['content'] ?? [])->firstWhere('type', 'tool_use');
        if (! is_array($tool['input'] ?? null)) throw new TerminalAiException('The AI provider returned malformed structured data.');
        return new AiResponse($tool['input'], (int) data_get($body, 'usage.input_tokens', 0), (int) data_get($body, 'usage.output_tokens', 0));
    }
}
