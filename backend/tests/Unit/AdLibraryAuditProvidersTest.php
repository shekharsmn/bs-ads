<?php

namespace Tests\Unit;

use App\Services\AdLibraryAudit\AnthropicAuditProvider;
use App\Services\AdLibraryAudit\GeminiAuditProvider;
use App\Services\AdLibraryAudit\OpenAiAuditProvider;
use App\Services\AdLibraryAudit\RetryableAiException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdLibraryAuditProvidersTest extends TestCase
{
    public function test_gemini_uses_structured_schema_and_query_key(): void
    {
        config(['ad_library_audit.providers.gemini.api_key' => 'secret', 'ad_library_audit.providers.gemini.base_url' => 'https://gemini.test/v1beta']);
        Http::fake(['gemini.test/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => '{"value":"ok"}']]]]], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 4]])]);
        $schema = [
            'type' => 'object',
            'properties' => [
                'value' => ['type' => 'string'],
                'evidence' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['fact' => ['type' => 'string']],
                    'required' => ['fact'],
                    'additionalProperties' => false,
                ]],
            ],
            'required' => ['value', 'evidence'],
            'additionalProperties' => false,
        ];
        $result = app(GeminiAuditProvider::class)->generateJson('model', 'system', 'prompt', $schema);
        $this->assertSame('ok', $result->data['value']);
        $this->assertSame(10, $result->inputTokens);
        Http::assertSent(function ($request) {
            $sentSchema = data_get($request->data(), 'generationConfig.responseSchema');

            return str_contains($request->url(), 'key=secret')
                && ! array_key_exists('additionalProperties', $sentSchema)
                && ! array_key_exists('additionalProperties', data_get($sentSchema, 'properties.evidence.items'));
        });
    }

    public function test_openai_normalizes_json_and_usage(): void
    {
        config(['ad_library_audit.providers.openai.api_key' => 'secret', 'ad_library_audit.providers.openai.base_url' => 'https://openai.test/v1']);
        Http::fake(['openai.test/*' => Http::response(['choices' => [['message' => ['content' => '{"value":"ok"}']]], 'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 5]])]);
        $result = app(OpenAiAuditProvider::class)->generateJson('model', 'system', 'prompt', ['type' => 'object']);
        $this->assertSame(['value' => 'ok'], $result->data);
        $this->assertSame(5, $result->outputTokens);
    }

    public function test_gemini_url_context_retrieves_the_supplied_website(): void
    {
        config(['ad_library_audit.providers.gemini.api_key' => 'secret', 'ad_library_audit.providers.gemini.base_url' => 'https://gemini.test/v1beta']);
        Http::fake(['gemini.test/*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => '{"value":"ok"}']]],
                'urlContextMetadata' => ['urlMetadata' => [[
                    'retrievedUrl' => 'https://brand.test/',
                    'urlRetrievalStatus' => 'URL_RETRIEVAL_STATUS_SUCCESS',
                ]]],
            ]],
            'usageMetadata' => ['promptTokenCount' => 20, 'candidatesTokenCount' => 4],
        ])]);

        $result = app(GeminiAuditProvider::class)->generateJsonFromUrl(
            'model',
            'system',
            'Extract brand context.',
            'https://brand.test/',
            ['type' => 'object', 'properties' => ['value' => ['type' => 'string']], 'required' => ['value']],
        );

        $this->assertSame('ok', $result->data['value']);
        Http::assertSent(fn ($request) => array_key_exists('url_context', data_get($request->data(), 'tools.0', []))
            && str_contains(data_get($request->data(), 'contents.0.parts.0.text', ''), 'https://brand.test/'));
    }

    public function test_anthropic_normalizes_tool_output(): void
    {
        config(['ad_library_audit.providers.anthropic.api_key' => 'secret', 'ad_library_audit.providers.anthropic.base_url' => 'https://anthropic.test/v1']);
        Http::fake(['anthropic.test/*' => Http::response(['content' => [['type' => 'tool_use', 'input' => ['value' => 'ok']]], 'usage' => ['input_tokens' => 8, 'output_tokens' => 3]])]);
        $result = app(AnthropicAuditProvider::class)->generateJson('model', 'system', 'prompt', ['type' => 'object']);
        $this->assertSame(['value' => 'ok'], $result->data);
    }

    public function test_rate_limit_is_retryable_without_exposing_provider_body(): void
    {
        config(['ad_library_audit.providers.openai.api_key' => 'secret', 'ad_library_audit.providers.openai.base_url' => 'https://openai.test/v1']);
        Http::fake(['openai.test/*' => Http::response(['error' => ['message' => 'secret provider detail']], 429)]);
        $this->expectException(RetryableAiException::class);
        $this->expectExceptionMessage('The AI provider rejected the request.');
        app(OpenAiAuditProvider::class)->generateJson('model', 'system', 'prompt', ['type' => 'object']);
    }
}
