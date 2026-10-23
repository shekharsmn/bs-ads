<?php

namespace App\Services\AdLibraryAudit;

use App\Models\AiModel;
use Illuminate\Validation\ValidationException;

class ModelCatalog
{
    public function available(?string $mediaDepth = null): array
    {
        return AiModel::query()->where('enabled', true)->orderByDesc('is_default')->orderBy('label')->get()
            ->filter(fn (AiModel $model) => $this->providerConfigured($model->provider))
            ->filter(fn (AiModel $model) => $this->supportsDepth($model->capabilities ?? [], $mediaDepth))
            ->map(fn (AiModel $model) => [
                'key' => $model->catalogKey(),
                'provider' => $model->provider,
                'model' => $model->model,
                'label' => $model->label,
                'capabilities' => $model->capabilities,
                'input_price' => (float) $model->input_price,
                'output_price' => (float) $model->output_price,
                'is_default' => $model->is_default,
            ])->values()->all();
    }

    public function get(string $key, ?string $mediaDepth = null): array
    {
        $model = collect($this->available($mediaDepth))->firstWhere('key', $key);
        if (! $model) {
            throw ValidationException::withMessages(['model_key' => 'The selected model is unavailable or does not support this media depth.']);
        }

        return $model;
    }

    public function estimate(string $key, int $inputTokens, int $outputTokens): float
    {
        $model = $this->findByKey($key);
        if (! $model) return 0;

        return round(($inputTokens / 1_000_000) * (float) $model->input_price + ($outputTokens / 1_000_000) * (float) $model->output_price, 6);
    }

    public function pricing(string $key): array
    {
        $model = $this->findByKey($key);
        return ['input_price' => (float) ($model?->input_price ?? 0), 'output_price' => (float) ($model?->output_price ?? 0)];
    }

    private function providerConfigured(string $provider): bool
    {
        $key = (string) config("ad_library_audit.providers.{$provider}.api_key");
        return $key !== '' && ! str_contains($key, 'YOUR_');
    }

    private function supportsDepth(array $capabilities, ?string $depth): bool
    {
        if (! $depth || $depth === 'text_only') return true;
        if (! in_array('vision', $capabilities, true)) return false;
        return $depth !== 'full_media' || in_array('audio', $capabilities, true);
    }

    private function findByKey(string $key): ?AiModel
    {
        [$provider, $model] = array_pad(explode(':', $key, 2), 2, null);
        if (! $provider || ! $model) return null;

        return AiModel::query()->where('provider', $provider)->where('model', $model)->first();
    }
}
