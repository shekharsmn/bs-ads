<?php

namespace App\Services\AdLibraryAudit;

use InvalidArgumentException;

class AiProviderRegistry
{
    public function __construct(
        private readonly GeminiAuditProvider $gemini,
        private readonly OpenAiAuditProvider $openai,
        private readonly AnthropicAuditProvider $anthropic,
    ) {}

    public function for(string $provider): AdLibraryAuditAiProvider
    {
        return match ($provider) {
            'gemini' => $this->gemini,
            'openai' => $this->openai,
            'anthropic' => $this->anthropic,
            default => throw new InvalidArgumentException('Unsupported AI provider.'),
        };
    }
}
