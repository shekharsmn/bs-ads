<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MetaAccount;
use App\Services\MetaFetchService;
use Illuminate\Http\Request;

class MetaAccountController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            $request->user()->hasMany(MetaAccount::class)->get(['id', 'label', 'account_id', 'account_name', 'currency', 'created_at'])
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'label'        => 'nullable|string|max:255',
            'account_id'   => 'required|string',
            'app_id'       => 'required|string',
            'app_secret'   => 'required|string',
            'access_token' => 'required|string',
        ]);

        // Validate credentials by hitting the API
        try {
            $fetcher = new MetaFetchService(
                $data['account_id'],
                $data['access_token'],
                $data['app_id'],
                $data['app_secret'],
            );
            $info = $fetcher->fetchAccountInfo();
        } catch (\Exception $e) {
            return response()->json(['error' => 'Invalid credentials: ' . $e->getMessage()], 422);
        }

        $account = $request->user()->metaAccounts()->create([
            'label'        => $data['label'] ?? ($info['name'] ?? 'My Meta Account'),
            'account_id'   => $data['account_id'],
            'app_id'       => $data['app_id'],
            'app_secret'   => $data['app_secret'],
            'access_token' => $data['access_token'],
            'account_name' => $info['name'] ?? null,
            'currency'     => $info['currency'] ?? null,
            'timezone'     => $info['timezone_name'] ?? null,
        ]);

        return response()->json(['account' => $account->only(['id', 'label', 'account_id', 'account_name', 'currency'])], 201);
    }

    public function update(Request $request, MetaAccount $metaAccount)
    {
        if ($metaAccount->user_id !== $request->user()->id) {
            abort(403);
        }

        $data = $request->validate([
            'label'        => 'nullable|string|max:255',
            'account_id'   => 'required|string',
            'app_id'       => 'required|string',
            'app_secret'   => 'nullable|string',
            'access_token' => 'nullable|string',
        ]);

        $newSecret = !empty($data['app_secret']) ? $data['app_secret'] : $metaAccount->app_secret;
        $newToken  = !empty($data['access_token']) ? $data['access_token'] : $metaAccount->access_token;

        $credentialsChanged = !empty($data['app_secret']) || !empty($data['access_token'])
            || $data['account_id'] !== $metaAccount->account_id
            || $data['app_id'] !== $metaAccount->app_id;

        if ($credentialsChanged) {
            try {
                $fetcher = new MetaFetchService($data['account_id'], $newToken, $data['app_id'], $newSecret);
                $info = $fetcher->fetchAccountInfo();
                $metaAccount->account_name = $info['name'] ?? $metaAccount->account_name;
                $metaAccount->currency     = $info['currency'] ?? $metaAccount->currency;
                $metaAccount->timezone     = $info['timezone_name'] ?? $metaAccount->timezone;
            } catch (\Exception $e) {
                return response()->json(['error' => 'Invalid credentials: ' . $e->getMessage()], 422);
            }
        }

        $metaAccount->label      = $data['label'] ?? $metaAccount->label;
        $metaAccount->account_id = $data['account_id'];
        $metaAccount->app_id     = $data['app_id'];
        if (!empty($data['app_secret'])) {
            $metaAccount->app_secret = $data['app_secret'];
        }
        if (!empty($data['access_token'])) {
            $metaAccount->access_token = $data['access_token'];
        }
        $metaAccount->save();

        return response()->json(['account' => $metaAccount->only(['id', 'label', 'account_id', 'account_name', 'currency'])]);
    }

    public function destroy(Request $request, MetaAccount $metaAccount)
    {
        if ($metaAccount->user_id !== $request->user()->id) {
            abort(403);
        }
        $metaAccount->delete();
        return response()->json(['message' => 'Account removed']);
    }
}
