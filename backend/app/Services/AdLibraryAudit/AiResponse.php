<?php

namespace App\Services\AdLibraryAudit;

final readonly class AiResponse
{
    public function __construct(
        public array $data,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {}
}
