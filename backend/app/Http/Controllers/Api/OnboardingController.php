<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AlertRule;
use App\Models\MetricSnapshot;
use App\Models\ReportRun;
use App\Services\ClientAccess;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function __invoke(Request $request): array
    {
        abort_unless($request->user()->isAdmin(), 403);
        $clients = fn () => ClientAccess::clientsFor($request->user());
        $ids = $clients()->pluck('id');
        $steps = [
            ['key' => 'client', 'label' => 'Create a client', 'complete' => $clients()->exists(), 'to' => '/clients'],
            ['key' => 'connection', 'label' => 'Connect an ad platform', 'complete' => $clients()->whereHas('connections')->exists(), 'to' => '/connections'],
            ['key' => 'account', 'label' => 'Assign an ad account', 'complete' => $clients()->whereHas('adAccounts')->exists(), 'to' => '/connections'],
            ['key' => 'collection', 'label' => 'Collect the first metrics', 'complete' => MetricSnapshot::whereHas('adAccount', fn ($q) => $q->whereIn('client_id', $ids))->exists(), 'to' => '/'],
            ['key' => 'alert', 'label' => 'Configure an alert policy', 'complete' => AlertRule::whereIn('client_id', $ids)->exists(), 'to' => '/alert-rules'],
            ['key' => 'report', 'label' => 'Generate the first report', 'complete' => ReportRun::whereIn('client_id', $ids)->where('status', 'complete')->exists(), 'to' => '/scheduled-reports'],
            ['key' => 'delivery', 'label' => 'Configure report delivery', 'complete' => $clients()->whereNotNull('google_chat_webhook')->exists(), 'to' => '/clients'],
        ];

        return ['steps' => $steps, 'completed' => collect($steps)->where('complete', true)->count(), 'total' => count($steps)];
    }
}
