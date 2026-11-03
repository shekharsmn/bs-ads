<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailIntegration;
use App\Services\EmailIntegrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class EmailIntegrationController extends Controller
{
    public function __construct(private readonly EmailIntegrationService $service)
    {
    }

    public function show()
    {
        return $this->service->payload();
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'region' => ['required', 'string', 'max:64', 'regex:/^[a-z]{2}(?:-gov)?-[a-z]+-\d$/'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'smtp_username' => ['nullable', 'string', 'max:512'],
            'smtp_password' => ['nullable', 'string', 'max:1024'],
            'from_address' => ['required', 'email:rfc', 'max:255'],
            'from_name' => ['required', 'string', 'max:255'],
        ]);

        $settings = EmailIntegration::query()->firstOrNew(['provider' => EmailIntegrationService::PROVIDER]);

        foreach (['enabled', 'region', 'port', 'from_address', 'from_name'] as $field) {
            $settings->{$field} = $data[$field];
        }

        foreach (['smtp_username', 'smtp_password'] as $secret) {
            if (trim((string) ($data[$secret] ?? '')) !== '') {
                $settings->{$secret} = $data[$secret];
            }
        }

        if ($settings->enabled && ! $this->service->configured($settings)) {
            throw ValidationException::withMessages([
                'smtp_username' => 'Amazon SES SMTP credentials are required before the integration can be enabled.',
            ]);
        }

        $settings->last_test_status = null;
        $settings->last_error = null;
        $settings->save();
        $this->service->apply($settings);

        return $this->service->payload($settings->fresh());
    }

    public function test(Request $request)
    {
        $data = $request->validate(['recipient' => ['required', 'email:rfc', 'max:255']]);
        $settings = $this->service->stored();

        if (! $settings?->enabled || ! $this->service->configured($settings)) {
            throw ValidationException::withMessages([
                'recipient' => 'Save and enable a complete Amazon SES configuration before sending a test email.',
            ]);
        }

        $this->service->apply($settings);

        try {
            Mail::raw('Amazon SES is connected to SharkAds. This message confirms the saved email integration can deliver mail.', function ($message) use ($data) {
                $message->to($data['recipient'])->subject('SharkAds Amazon SES test');
            });

            $settings->forceFill([
                'last_tested_at' => now(),
                'last_test_status' => 'success',
                'last_error' => null,
            ])->save();
        } catch (\Throwable $error) {
            report($error);
            $settings->forceFill([
                'last_tested_at' => now(),
                'last_test_status' => 'failed',
                'last_error' => mb_substr($error->getMessage(), 0, 2000),
            ])->save();

            return response()->json([
                'message' => 'Amazon SES could not send the test email. Check the credentials, region, and verified sender.',
                'integration' => $this->service->payload($settings->fresh()),
            ], 422);
        }

        return [
            'message' => 'Test email sent.',
            'integration' => $this->service->payload($settings->fresh()),
        ];
    }
}
