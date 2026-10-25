<?php

namespace App\Services\AdLibraryAudit;

interface UrlContextAuditProvider
{
    public function generateJsonFromUrl(string $model, string $system, string $prompt, string $url, array $schema): AiResponse;
}
