<?php

namespace App\Services;

use App\Models\Ad;
use App\Models\CreativeMediaSetting;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreativeMediaProxyService
{
    private const PROVIDER = 'corsproxy_io';

    private const ALLOWED_HOST_SUFFIXES = [
        'fbcdn.net', 'facebook.com', 'fbsbx.com', 'cdninstagram.com',
        'googleusercontent.com', 'gstatic.com', 'google.com', 'googleapis.com',
        'ytimg.com', 'youtube.com', 'youtu.be',
    ];

    public function proxyPayload(Ad $ad, int $mediaIndex): array
    {
        $media = data_get($ad->creative, "media.{$mediaIndex}");
        abort_unless(is_array($media), 404);
        $target = (string) ($media['source_url'] ?? $media['url'] ?? '');
        $this->assertAllowed($target);

        $setting = CreativeMediaSetting::query()->where('provider', self::PROVIDER)->first();
        if (! $setting?->enabled || ! $setting->api_key) {
            throw ValidationException::withMessages(['media' => 'The creative media proxy is not configured.']);
        }

        $extension = pathinfo((string) parse_url($target, PHP_URL_PATH), PATHINFO_EXTENSION);
        $extension = preg_match('/^[a-zA-Z0-9]{2,5}$/', $extension) ? strtolower($extension) : ($this->isVideo($media) ? 'mp4' : 'jpg');

        return [
            'url' => 'https://corsproxy.io/?'.http_build_query(['key' => $setting->api_key, 'url' => $target], '', '&', PHP_QUERY_RFC3986),
            'filename' => Str::slug($ad->name ?: 'creative').'-'.($mediaIndex + 1).'.'.$extension,
            'content_type' => $media['content_type'] ?? null,
        ];
    }

    public function targetHost(Ad $ad, int $mediaIndex): ?string
    {
        $target = (string) (data_get($ad->creative, "media.{$mediaIndex}.source_url") ?? data_get($ad->creative, "media.{$mediaIndex}.url") ?? '');

        return parse_url($target, PHP_URL_HOST) ?: null;
    }

    private function assertAllowed(string $target): void
    {
        $parts = parse_url($target);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowed = ($parts['scheme'] ?? null) === 'https' && collect(self::ALLOWED_HOST_SUFFIXES)
            ->contains(fn (string $suffix) => $host === $suffix || str_ends_with($host, '.'.$suffix));
        if (! $allowed) {
            throw ValidationException::withMessages(['media' => 'This creative media host is not approved for proxy downloads.']);
        }
    }

    private function isVideo(array $media): bool
    {
        return str_contains(strtolower((string) ($media['type'] ?? '')), 'video');
    }
}
