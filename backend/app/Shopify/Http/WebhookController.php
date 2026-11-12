<?php

namespace App\Shopify\Http;

use App\Shopify\InstallationService;
use App\Shopify\Jobs\ProcessWebhook;
use App\Shopify\Models\AppRegistration;
use App\Shopify\Models\Installation;
use App\Shopify\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WebhookController
{
    public function __invoke(Request $r, string $registration)
    {
        $app = AppRegistration::where('slug', $registration)->firstOrFail();
        abort_if(strlen($r->getContent()) > 2097152, 413);
        $signature = base64_encode(hash_hmac('sha256', $r->getContent(), $app->secret, true));
        $valid = hash_equals($signature, (string) $r->header('X-Shopify-Hmac-Sha256'));
        if (! $valid && $app->previous_secret) {
            $valid = hash_equals(base64_encode(hash_hmac('sha256', $r->getContent(), $app->previous_secret, true)), (string) $r->header('X-Shopify-Hmac-Sha256'));
        }
        abort_unless($valid, 401);
        $domain = strtolower((string) $r->header('X-Shopify-Shop-Domain'));
        try {
            $domain = \App\Shopify\Identity::domain($domain);
        } catch (\Throwable) {
            abort(400);
        }
        $topic = (string) $r->header('X-Shopify-Topic');
        $delivery = (string) $r->header('X-Shopify-Webhook-Id');
        abort_unless(preg_match('/^[a-zA-Z0-9-]{1,191}$/D', $delivery), 400);
        $topics = ['orders/create', 'orders/updated', 'orders/paid', 'orders/cancelled', 'orders/delete', 'refunds/create', 'app/uninstalled', 'app/scopes_update', 'customers/data_request', 'customers/redact', 'shop/redact'];
        abort_unless(in_array($topic, $topics, true), 400);
        $payload = json_decode($r->getContent(), true);
        abort_unless(is_array($payload), 400);
        $install = Installation::where('app_id', $app->id)->whereHas('store', fn ($q) => $q->where('domain', $domain))->first();
        if (! $install && isset($payload['shop_id'])) {
            $install = Installation::where('app_id', $app->id)->whereHas('store', fn ($q) => $q->where('gid', 'gid://shopify/Shop/'.$payload['shop_id']))->first();
            if ($install) {
                $domain = $install->store->domain;
            }
        }
        $event = DB::transaction(function () use ($app, $install, $topic, $delivery, $domain, $payload, $r) {
            // Retain only operational IDs, never customer addresses/contact details.
            $safe = array_intersect_key($payload, array_flip(['id', 'order_id', 'orders_requested', 'orders_to_redact', 'shop_id', 'current', 'data_request']));
            if (isset($safe['data_request'])) {
                $safe['data_request'] = ['id' => $safe['data_request']['id'] ?? null];
            }
            $event = WebhookEvent::firstOrCreate(['app_id' => $app->id, 'delivery_id' => $delivery], ['install_id' => $install?->id, 'generation' => $install?->generation, 'domain' => $domain, 'topic' => $topic, 'payload' => $safe]);
            if (in_array($topic, ['customers/data_request', 'customers/redact', 'shop/redact'], true)) {
                $ids = $safe['orders_to_redact'] ?? $safe['orders_requested'] ?? [];
                $privacy = \App\Shopify\Models\PrivacyRequest::firstOrCreate(['app_id' => $app->id, 'request_key' => $delivery], [
                    'domain' => $domain, 'topic' => $topic, 'order_gids' => array_map(fn ($id) => 'gid://shopify/Order/'.$id, $ids),
                ]);
                if ($privacy->wasRecentlyCreated && $topic !== 'customers/data_request') {
                    app(\App\Shopify\PrivacyLedger::class)->record($privacy);
                }
            }
            if ($event->wasRecentlyCreated && $install && $topic === 'app/uninstalled') {
                $triggered = \Carbon\CarbonImmutable::parse($r->header('X-Shopify-Triggered-At') ?? now());
                if (! $install->installed_at || $triggered->gte($install->installed_at)) {
                    app(InstallationService::class)->revoke($install);
                }
            }

            return $event;
        });
        // Durable inbox sweep recovers dispatch failure after acknowledgment.
        try {
            ProcessWebhook::dispatch($event->id);
        } catch (\Throwable) { /* Event is already durable. */
        }

        return response('', 200);
    }
}
