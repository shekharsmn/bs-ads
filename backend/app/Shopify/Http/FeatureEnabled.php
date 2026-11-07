<?php

namespace App\Shopify\Http;

final class FeatureEnabled
{
    public function handle($request, \Closure $next)
    {
        abort_unless(config('shopify.enabled'), 404);

        return $next($request);
    }
}
