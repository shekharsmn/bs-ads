<?php

namespace App\Services;

use App\Models\EmailIntegration;
use Illuminate\Support\Facades\Schema;

class EmailIntegrationService
{
    public const PROVIDER = 'amazon_ses';

    public function stored(): ?EmailIntegration
    {
        if (! Schema::hasTable('email_integrations')) {
            return null;
        }

        return EmailIntegration::query()->where('provider', self::PROVIDER)->first();
    }

    public function configured(?EmailIntegration $settings): bool
    {
        return $settings !== null
            && trim((string) $settings->smtp_username) !== ''
            && trim((string) $settings->smtp_password) !== ''
            && trim((string) $settings->from_address) !== '';
    }

    public function apply(?EmailIntegration $settings = null, bool $purge = true): bool
    {
        $settings ??= $this->stored();

        if (! $settings?->enabled || ! $this->configured($settings)) {
            return false;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.scheme' => null,
            'mail.mailers.smtp.url' => null,
            'mail.mailers.smtp.host' => $this->endpoint($settings->region),
            'mail.mailers.smtp.port' => $settings->port,
            'mail.mailers.smtp.username' => $settings->smtp_username,
            'mail.mailers.smtp.password' => $settings->smtp_password,
            'mail.mailers.smtp.timeout' => 20,
            'mail.from.address' => $settings->from_address,
            'mail.from.name' => $settings->from_name,
        ]);

        if ($purge && app()->bound('mail.manager')) {
            app('mail.manager')->purge('smtp');
        }

        return true;
    }

    public function endpoint(string $region): string
    {
        return "email-smtp.{$region}.amazonaws.com";
    }

    public function payload(?EmailIntegration $settings = null): array
    {
        $settings ??= $this->stored();
        $username = trim((string) ($settings?->smtp_username ?? ''));

        return [
            'provider' => self::PROVIDER,
            'provider_label' => 'Amazon SES',
            'enabled' => (bool) ($settings?->enabled ?? false),
            'configured' => $this->configured($settings),
            'region' => $settings?->region ?? 'us-east-1',
            'endpoint' => $this->endpoint($settings?->region ?? 'us-east-1'),
            'port' => $settings?->port ?? 587,
            'smtp_username_configured' => $username !== '',
            'smtp_username_hint' => $username === '' ? null : '••••'.substr($username, -4),
            'smtp_password_configured' => trim((string) ($settings?->smtp_password ?? '')) !== '',
            'from_address' => $settings?->from_address ?? (string) config('mail.from.address'),
            'from_name' => $settings?->from_name ?? (string) config('mail.from.name'),
            'last_tested_at' => $settings?->last_tested_at?->toIso8601String(),
            'last_test_status' => $settings?->last_test_status,
            'last_test_error' => $settings?->last_test_status === 'failed'
                ? 'The last test failed. Check the SMTP credentials, region, and verified sender.'
                : null,
        ];
    }
}
