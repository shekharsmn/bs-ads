<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ClientScopeResolver;
use App\Services\MetricsQueryService;
use Illuminate\Http\Request;

class MetricsController extends Controller
{
    public function __construct(private readonly MetricsQueryService $metrics, private readonly ClientScopeResolver $scopes) {}

    public function summary(Request $request)
    {
        return $this->run($request, 'summary');
    }

    public function trends(Request $request)
    {
        return $this->run($request, 'trends');
    }

    public function campaigns(Request $request)
    {
        return $this->run($request, 'campaigns');
    }

    private function run(Request $request, string $method)
    {
        $data = $request->validate(['client_id' => 'required|exists:clients,id', 'ad_account_id' => 'nullable|exists:ad_accounts,id', 'start_date' => 'required|date', 'end_date' => 'required|date|after_or_equal:start_date']);
        $scope = $this->scopes->resolve($request->user(), $data);

        return $this->metrics->{$method}($data + ['client' => $scope->client]);
    }
}
