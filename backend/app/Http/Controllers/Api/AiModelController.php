<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdLibraryAudit;
use App\Models\AiModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AiModelController extends Controller
{
    private const PROVIDERS = ['gemini', 'openai', 'anthropic'];
    private const CAPABILITIES = ['text', 'vision', 'audio', 'structured_json'];

    public function index()
    {
        return [
            'models' => AiModel::query()->orderByDesc('is_default')->orderBy('provider')->orderBy('label')->get()->map(fn (AiModel $model) => $this->payload($model)),
            'providers' => collect(self::PROVIDERS)->map(fn (string $provider) => [
                'key' => $provider,
                'label' => ucfirst($provider),
                'configured' => $this->providerConfigured($provider),
            ])->values(),
            'capabilities' => self::CAPABILITIES,
        ];
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $model = DB::transaction(function () use ($data) {
            if ($data['is_default']) {
                AiModel::query()->update(['is_default' => false]);
                $data['enabled'] = true;
            }

            $model = AiModel::create($data);
            $this->ensureDefault();

            return $model->fresh();
        });

        return response()->json($this->payload($model), 201);
    }

    public function update(Request $request, AiModel $aiModel)
    {
        $data = $this->validated($request, $aiModel);
        $identityChanged = $data['provider'] !== $aiModel->provider || $data['model'] !== $aiModel->model;
        if ($identityChanged && $this->usedByAudit($aiModel)) {
            throw ValidationException::withMessages(['model' => 'The provider and model identifier cannot change after the model has been used by an audit.']);
        }

        DB::transaction(function () use ($data, $aiModel) {
            if ($data['is_default']) {
                AiModel::query()->where('id', '!=', $aiModel->id)->update(['is_default' => false]);
                $data['enabled'] = true;
            }

            $aiModel->update($data);
            $this->ensureDefault();
        });

        return $this->payload($aiModel->fresh());
    }

    public function destroy(AiModel $aiModel)
    {
        if ($this->usedByAudit($aiModel)) {
            throw ValidationException::withMessages(['model' => 'This model is used by an existing audit. Disable it instead of deleting it.']);
        }

        DB::transaction(function () use ($aiModel) {
            $aiModel->delete();
            $this->ensureDefault();
        });

        return response()->noContent();
    }

    private function validated(Request $request, ?AiModel $aiModel = null): array
    {
        $uniqueModel = Rule::unique('ai_models', 'model')->where(fn ($query) => $query->where('provider', $request->input('provider')));
        if ($aiModel) $uniqueModel->ignore($aiModel->id);

        $data = $request->validate([
            'provider' => ['required', Rule::in(self::PROVIDERS)],
            'model' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/', $uniqueModel],
            'label' => ['required', 'string', 'max:160'],
            'capabilities' => ['required', 'array', 'min:1'],
            'capabilities.*' => ['required', 'distinct', Rule::in(self::CAPABILITIES)],
            'input_price' => ['required', 'numeric', 'min:0', 'max:999999.999999'],
            'output_price' => ['required', 'numeric', 'min:0', 'max:999999.999999'],
            'enabled' => ['required', 'boolean'],
            'is_default' => ['required', 'boolean'],
        ]);

        if (in_array('audio', $data['capabilities'], true) && ! in_array('vision', $data['capabilities'], true)) {
            throw ValidationException::withMessages(['capabilities' => 'Audio models must also support vision for full-media audits.']);
        }
        if (! in_array('text', $data['capabilities'], true) || ! in_array('structured_json', $data['capabilities'], true)) {
            throw ValidationException::withMessages(['capabilities' => 'Content-audit models must support text and structured JSON.']);
        }

        return $data;
    }

    private function ensureDefault(): void
    {
        if (AiModel::query()->where('enabled', true)->where('is_default', true)->exists()) return;
        AiModel::query()->where('enabled', true)->orderBy('id')->first()?->update(['is_default' => true]);
    }

    private function usedByAudit(AiModel $model): bool
    {
        return AdLibraryAudit::query()->where('provider', $model->provider)->where('model', $model->model)->exists();
    }

    private function providerConfigured(string $provider): bool
    {
        $key = (string) config("ad_library_audit.providers.{$provider}.api_key");
        return $key !== '' && ! str_contains($key, 'YOUR_');
    }

    private function payload(AiModel $model): array
    {
        return [
            'id' => $model->id,
            'key' => $model->catalogKey(),
            'provider' => $model->provider,
            'model' => $model->model,
            'label' => $model->label,
            'capabilities' => $model->capabilities,
            'input_price' => (float) $model->input_price,
            'output_price' => (float) $model->output_price,
            'enabled' => $model->enabled,
            'is_default' => $model->is_default,
            'provider_configured' => $this->providerConfigured($model->provider),
        ];
    }
}
