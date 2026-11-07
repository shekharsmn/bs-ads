<?php

namespace App\Shopify\Http;

use App\Shopify\Identity;
use App\Shopify\InstallationService;
use App\Shopify\Models\AppRegistration;
use App\Shopify\Models\Installation;
use App\Shopify\ShopifyException;
use Closure;
use Illuminate\Http\Request;

final class EmbeddedAuth
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('shopify.enabled'), 404);
        $app = AppRegistration::where('slug', $request->header('X-Shopify-Registration'))->where('active', true)->firstOrFail();
        try {
            $jwt = $request->bearerToken() ?? '';
            $identity = app(Identity::class)->verify($jwt, $app);
            if ($request->isMethod('POST') && $request->is('api/shopify/embedded/session')) {
                $install = app(InstallationService::class)->establish($app, $identity['domain'], $jwt);
            } else {
                $install = Installation::where('app_id', $app->id)->where('status', 'active')->whereHas('store', fn ($q) => $q->where('domain', $identity['domain']))->first();
                if (! $install) {
                    throw new ShopifyException('reconnect_required');
                }
            }
        } catch (ShopifyException $e) {
            return response()->json(['message' => $e->errorCode], $e->retryable ? 503 : 401);
        }
        $request->attributes->set('shop_install', $install);

        return $next($request);
    }
}
