<?php

namespace App\Services\AdLibraryAudit;

interface AdLibraryAuditAiProvider
{
    /** @param array<int,array{mime:string,data:string}> $media */
    public function generateJson(string $model, string $system, string $prompt, array $schema, array $media = []): AiResponse;
}
