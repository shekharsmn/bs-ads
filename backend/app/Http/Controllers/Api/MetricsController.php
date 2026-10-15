<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdAccount;
use App\Models\Client;
use App\Services\ClientAccess;
use App\Services\MetricsQueryService;
use Illuminate\Http\Request;

class MetricsController extends Controller
{
    public function __construct(private readonly MetricsQueryService $metrics) {}
    public function summary(Request $request) { return $this->run($request, 'summary'); }
    public function trends(Request $request) { return $this->run($request, 'trends'); }
    public function campaigns(Request $request) { return $this->run($request, 'campaigns'); }
    private function run(Request $request, string $method)
    {
        $data = $request->validate(['client_id' => 'required|exists:clients,id', 'ad_account_id' => 'nullable|exists:ad_accounts,id', 'start_date' => 'required|date', 'end_date' => 'required|date|after_or_equal:start_date']);
        $client = ClientAccess::authorize($request->user(), Client::findOrFail($data['client_id']));
        if ($data['ad_account_id'] ?? null) abort_unless(AdAccount::whereKey($data['ad_account_id'])->where('client_id', $client->id)->exists(), 422, 'Account does not belong to the client.');
        return $this->metrics->{$method}($data + ['client' => $client]);
    }
}
