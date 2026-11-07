<?php

namespace App\Shopify\Http;

use App\Shopify\CommerceReadService;
use App\Shopify\InstallationService;
use App\Shopify\LinkService;
use App\Shopify\Models\AppRegistration;
use App\Shopify\SyncCoordinator;
use Illuminate\Http\Request;

final class MerchantController
{
    public function shell(Request $r, string $registration)
    {
        abort_unless(config('shopify.enabled'), 404);
        $app = AppRegistration::where('slug', $registration)->where('active', true)->firstOrFail();
        $shop = strtolower((string) $r->query('shop'));
        try {
            $shop = \App\Shopify\Identity::domain($shop);
        } catch (\Throwable) {
            abort(400, 'Open this app from Shopify admin.');
        }

        // Only this small shell can be framed. Authorization still requires a valid bearer token.
        $assets = (new \Illuminate\Foundation\Vite)
            ->useHotFile(public_path('shopify.hot'))
            ->createAssetPathsUsing(fn ($path) => '/'.ltrim($path, '/'));

        return response()->view('shopify.app', ['clientKey' => $app->client_key, 'registration' => $app->slug, 'assets' => $assets('src/main.jsx', 'shopify')])
            ->header('Content-Security-Policy', "frame-ancestors https://{$shop} https://admin.shopify.com;")
            ->header('Cache-Control', 'no-store');
    }

    public function session(Request $r, SyncCoordinator $sync)
    {
        $install = $r->attributes->get('shop_install');
        $warning = null;
        try {
            if (! $install->store->history_complete || $install->store->active_install_id !== $install->id) {
                $sync->enqueue($install, 'history');
            }
        } catch (\App\Shopify\ShopifyException $e) {
            $warning = $e->errorCode;
        }

        return ['store' => $install->store, 'registration' => $install->app->only(['name', 'distribution', 'client_id']), 'warning' => $warning];
    }

    public function report(Request $r, CommerceReadService $reports)
    {
        $install = $r->attributes->get('shop_install');
        $report = $reports->report($install->store, $r->query());
        if ($install->store->active_install_id !== $install->id) {
            $run = \App\Shopify\Models\SyncRun::where('install_id', $install->id)->latest('id')->first();
            $report['connection'] = ['status' => $install->status, 'run' => $run?->only(['id', 'stage', 'status', 'records', 'error_code'])];
            $report['coverage']['complete'] = false;
            $report['warnings'][] = 'Replacement installation history is not yet verified. Existing store figures remain visible.';
        }

        return $report;
    }

    public function refresh(Request $r, SyncCoordinator $sync)
    {
        return response()->json(['run' => $sync->enqueue($r->attributes->get('shop_install'))], 202);
    }

    public function link(Request $r, LinkService $links)
    {
        $data = $r->validate(['token' => 'required|string|size:48', 'confirm' => 'required|accepted']);
        $links->confirm($r->attributes->get('shop_install'), $data['token']);

        return response()->noContent();
    }

    public function disconnect(Request $r, InstallationService $installs)
    {
        $installs->revoke($r->attributes->get('shop_install'));

        return response()->json(['message' => 'Synchronization stopped. Uninstall BS Ads in Shopify to remove authorization and initiate store data deletion.']);
    }

    public function privacy(Request $r)
    {
        $install = $r->attributes->get('shop_install');

        return \App\Shopify\Models\PrivacyRequest::where('app_id', $install->app_id)->where('domain', $install->store->domain)->get(['id', 'topic', 'status', 'created_at', 'completed_at']);
    }

    public function exportPrivacy(Request $r, int $privacy)
    {
        $install = $r->attributes->get('shop_install');
        $request = \App\Shopify\Models\PrivacyRequest::whereKey($privacy)->where('app_id', $install->app_id)->where('domain', $install->store->domain)->firstOrFail();
        abort_unless(in_array($request->status, ['export_ready', 'completed'], true), 409, 'Export is not ready.');
        $path = 'shopify/privacy-'.$request->id.'.enc';
        abort_unless(\Illuminate\Support\Facades\Storage::disk('local')->exists($path), 410, 'Export has expired. Contact support to regenerate it.');
        $json = decrypt(\Illuminate\Support\Facades\Storage::disk('local')->get($path));
        $request->update(['status' => 'completed', 'completed_at' => now()]);

        return response($json, 200, ['Content-Type' => 'application/json', 'Content-Disposition' => 'attachment; filename="shopify-data.json"', 'Cache-Control' => 'no-store']);
    }
}
