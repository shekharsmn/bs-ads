<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CreativeMediaSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CreativeMediaSettingController extends Controller
{
    private const PROVIDER = 'corsproxy_io';

    public function show(): array
    {
        return $this->payload($this->stored());
    }

    public function update(Request $request): array
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'api_key' => ['nullable', 'string', 'max:2048'],
        ]);
        $setting = CreativeMediaSetting::query()->firstOrNew(['provider' => self::PROVIDER]);
        $setting->enabled = $data['enabled'];
        if (trim((string) ($data['api_key'] ?? '')) !== '') {
            $setting->api_key = trim($data['api_key']);
        }
        if ($setting->enabled && ! $setting->api_key) {
            throw ValidationException::withMessages(['api_key' => 'A corsproxy.io API key is required before the integration can be enabled.']);
        }
        $setting->save();

        return $this->payload($setting->fresh());
    }

    private function stored(): ?CreativeMediaSetting
    {
        return CreativeMediaSetting::query()->where('provider', self::PROVIDER)->first();
    }

    private function payload(?CreativeMediaSetting $setting): array
    {
        $key = (string) ($setting?->api_key ?? '');

        return [
            'provider' => self::PROVIDER,
            'enabled' => (bool) ($setting?->enabled ?? false),
            'configured' => $key !== '',
            'api_key_hint' => $key !== '' ? 'Saved - ending in '.substr($key, -4) : null,
            'browser_mode' => true,
        ];
    }
}
