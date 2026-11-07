<?php

namespace App\Http\Middleware;

use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/** Shopify endpoints authenticate signatures/bearer tokens, never agency cookies. */
final class AgencyStatefulRequests extends EnsureFrontendRequestsAreStateful
{
    public static function fromFrontend($request)
    {
        if ($request->is('api/shopify/embedded/*', 'api/shopify/webhooks/*')) {
            return false;
        }

        return parent::fromFrontend($request);
    }
}
