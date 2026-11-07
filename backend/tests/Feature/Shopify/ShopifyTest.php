<?php

namespace Tests\Feature\Shopify;

use App\Models\Client;
use App\Models\User;
use App\Shopify\CommerceReadService;
use App\Shopify\Identity;
use App\Shopify\LinkService;
use App\Shopify\Models\AppRegistration;
use App\Shopify\Models\Installation;
use App\Shopify\Models\Order;
use App\Shopify\Models\Sale;
use App\Shopify\Models\Store;
use App\Shopify\Models\SyncRun;
use App\Shopify\Models\WebhookEvent;
use App\Shopify\OrderImporter;
use App\Shopify\ShopifyException;
use App\Shopify\SyncEngine;
use App\Shopify\TokenProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ShopifyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shopify.enabled' => true]);
        Http::preventStrayRequests();
        Queue::fake();
        \Illuminate\Support\Facades\Storage::fake('local');
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function fixture(): array
    {
        $client = Client::create(['name' => 'Store client', 'slug' => 'store-client', 'objective' => 'ecommerce', 'timezone' => 'Asia/Kolkata', 'currency' => 'INR']);
        $app = AppRegistration::create(['slug' => 'pilot', 'name' => 'Pilot', 'client_key' => 'shopify-client-id', 'secret' => 'synthetic-secret-123456', 'distribution' => 'custom', 'client_id' => $client->id, 'data_approved' => true]);
        $store = Store::create(['gid' => 'gid://shopify/Shop/1', 'domain' => 'fixture.myshopify.com', 'name' => 'Fixture', 'timezone' => 'Asia/Kolkata', 'currency' => 'INR', 'client_id' => $client->id]);
        $install = Installation::create(['app_id' => $app->id, 'store_id' => $store->id, 'tokens' => ['access_token' => 'private-token', 'refresh_token' => 'private-refresh'], 'expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(90), 'scopes' => ['read_orders'], 'status' => 'active']);
        $store->update(['active_install_id' => $install->id]);

        return [$client, $app, $store, $install];
    }

    private function jwt(AppRegistration $app, array $override = []): string
    {
        $encode = fn ($v) => rtrim(strtr(base64_encode(json_encode($v)), '+/', '-_'), '=');
        $header = $encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $body = $encode(array_replace(['aud' => $app->client_key, 'dest' => 'https://fixture.myshopify.com', 'iss' => 'https://fixture.myshopify.com/admin', 'sub' => '123', 'iat' => now()->timestamp, 'nbf' => now()->timestamp, 'exp' => now()->addMinute()->timestamp], $override));

        return $header.'.'.$body.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $header.'.'.$body, $app->secret, true)), '+/', '-_'), '=');
    }

    public function test_identity_rejects_wrong_audience_expiry_and_destination(): void
    {
        [, $app] = $this->fixture();
        $identity = app(Identity::class);
        $this->assertSame('fixture.myshopify.com', $identity->verify($this->jwt($app), $app)['domain']);
        foreach ([['aud' => 'wrong'], ['exp' => now()->subSecond()->timestamp], ['dest' => 'http://127.0.0.1'], ['iss' => 'https://attacker.myshopify.com/admin']] as $claims) {
            try {
                $identity->verify($this->jwt($app, $claims), $app);
                $this->fail('Untrusted identity accepted');
            } catch (ShopifyException $e) {
                $this->assertNotEmpty($e->errorCode);
            }
        }
    }

    public function test_embedded_shell_uses_same_origin_assets_behind_an_https_tunnel(): void
    {
        $this->fixture();
        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        $disk->put('test-public/shopify/manifest.json', json_encode(['src/main.jsx' => ['src' => 'src/main.jsx', 'file' => 'assets/fixture.js', 'isEntry' => true, 'css' => ['assets/fixture.css']]]));
        $original = public_path();
        app()->usePublicPath($disk->path('test-public'));
        try {
            $response = $this->get('/shopify/app/pilot?shop=fixture.myshopify.com');
            $response->assertOk()->assertSee('src="/shopify/assets/fixture.js"', false)->assertSee('href="/shopify/assets/fixture.css"', false);
            $response->assertHeader('Content-Security-Policy', 'frame-ancestors https://fixture.myshopify.com https://admin.shopify.com;');
        } finally {
            app()->usePublicPath($original);
        }
    }

    public function test_credentials_are_encrypted_and_never_serialized(): void
    {
        [, $app,, $install] = $this->fixture();
        $this->assertStringNotContainsString('synthetic-secret', $app->getRawOriginal('secret'));
        $this->assertStringNotContainsString('private-token', $install->getRawOriginal('tokens'));
        $this->assertArrayNotHasKey('tokens', $install->toArray());
        $this->assertArrayNotHasKey('secret', $app->toArray());
    }

    public function test_connect_button_uses_only_the_clients_configured_custom_registration(): void
    {
        [$client, $app] = $this->fixture();
        $app->update(['install_url' => 'https://admin.shopify.com/oauth/install?client_id=synthetic']);
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer)->postJson('/api/clients/'.$client->id.'/shopify/connect')->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson('/api/clients/'.$client->id.'/shopify/connect')->assertOk()->assertExactJson(['url' => $app->install_url]);
        $other = Client::create(['name' => 'Other', 'slug' => 'other-commerce', 'objective' => 'ecommerce']);
        $this->postJson('/api/clients/'.$other->id.'/shopify/connect')->assertStatus(409);
        $app->update(['data_approved' => false]);
        $this->postJson('/api/clients/'.$client->id.'/shopify/connect')->assertStatus(409);
    }

    public function test_verified_custom_installation_links_its_preassigned_client_without_a_manual_code(): void
    {
        [$client, $app, $store] = $this->fixture();
        $store->update(['client_id' => null]);
        app(\App\Shopify\InstallationService::class)->establish($app, $store->domain, 'already-verified-identity');
        $this->assertSame($client->id, $store->fresh()->client_id);

        $app->update(['distribution' => 'public', 'client_id' => null]);
        $store->refresh()->update(['client_id' => null]);
        app(\App\Shopify\InstallationService::class)->establish($app->fresh(), $store->domain, 'already-verified-identity');
        $this->assertNull($store->fresh()->client_id);
    }

    public function test_custom_auto_link_never_replaces_an_existing_store(): void
    {
        [$client, $app, $store] = $this->fixture();
        $store->update(['client_id' => null]);
        Store::create(['gid' => 'gid://shopify/Shop/99', 'domain' => 'other.myshopify.com', 'name' => 'Existing store', 'timezone' => 'UTC', 'currency' => 'INR', 'client_id' => $client->id]);
        try {
            app(\App\Shopify\InstallationService::class)->establish($app, $store->domain, 'already-verified-identity');
            $this->fail('Existing association was replaced');
        } catch (ShopifyException $e) {
            $this->assertSame('replacement_confirmation_required', $e->errorCode);
        }
        $this->assertNull($store->fresh()->client_id);
    }

    public function test_operator_can_configure_existing_registration_without_a_client_facing_form(): void
    {
        [$client, $app] = $this->fixture();
        $this->artisan('shopify:configure', ['slug' => $app->slug, '--client' => $client->id, '--name' => $app->name, '--shopify-client-id' => $app->client_key, '--install-url' => 'https://admin.shopify.com/oauth/install?client_id=synthetic', '--data-access-confirmed' => true])
            ->expectsQuestion('Shopify client secret', 'synthetic-secret-123456')->expectsOutput('Shopify connection configured.')->assertSuccessful();
        $this->assertSame('https://admin.shopify.com/oauth/install?client_id=synthetic', $app->fresh()->install_url);
        $this->assertTrue($app->fresh()->data_approved);
    }

    public function test_merchant_report_is_store_scoped_and_does_not_need_agency_login(): void
    {
        [, $app,$store] = $this->fixture();
        $this->withHeaders(['Authorization' => 'Bearer '.$this->jwt($app), 'X-Shopify-Registration' => $app->slug])->getJson('/api/shopify/embedded/report')->assertOk()->assertJsonPath('store.id', $store->id);
        $this->withHeaders(['Authorization' => 'Bearer invalid', 'X-Shopify-Registration' => $app->slug])->getJson('/api/shopify/embedded/report')->assertUnauthorized();
    }

    public function test_agency_report_requires_client_assignment_and_admin_mutations(): void
    {
        [$client] = $this->fixture();
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer)->getJson("/api/clients/{$client->id}/shopify/report")->assertForbidden();
        $viewer->clients()->attach($client);
        $this->actingAs($viewer)->getJson("/api/clients/{$client->id}/shopify/report")->assertOk();
        $this->actingAs($viewer)->postJson("/api/clients/{$client->id}/shopify/link")->assertForbidden();
    }

    public function test_link_code_expires_and_cannot_be_replayed(): void
    {
        [$client,,,$install] = $this->fixture();
        $admin = User::factory()->create(['role' => 'admin']);
        $links = app(LinkService::class);
        $token = $links->create($client, $admin, false);
        $links->confirm($install, $token);
        try {
            $links->confirm($install, $token);
            $this->fail('Replay accepted');
        } catch (ShopifyException $e) {
            $this->assertSame('link_expired', $e->errorCode);
        }
        $token = $links->create($client, $admin, false);
        $this->travel(16)->minutes();
        $this->expectException(ShopifyException::class);
        $links->confirm($install, $token);
    }

    public function test_refund_reduces_november_not_october_and_currencies_remain_separate(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 10));
        [, ,$store,$install] = $this->fixture();
        $order = Order::create(['store_id' => $store->id, 'install_id' => $install->id, 'gid' => 'gid://shopify/Order/1', 'placed_at' => '2026-10-10 10:00:00', 'source_updated_at' => now(), 'financial_status' => 'REFUNDED', 'sales_key' => 'snapshot', 'sync_state' => 'ready']);
        foreach ([['one', '2026-10-10', 'INR', '100.125000'], ['two', '2026-11-03', 'INR', '-20.125000'], ['three', '2026-11-03', 'USD', '1.000000']] as [$id,$date,$currency,$amount]) {
            Sale::create(['store_id' => $store->id, 'order_id' => $order->id, 'install_id' => $install->id, 'gid' => $id, 'sync_key' => 'snapshot', 'agreement_gid' => 'agreement', 'happened_at' => $date.' 10:00:00', 'line_type' => 'PRODUCT', 'action_type' => 'ORDER', 'currency' => $currency, 'amount' => $amount, 'tax' => '0', 'discount' => '0', 'net' => $amount]);
        }
        // A partially imported replacement is not visible.
        Sale::create(['store_id' => $store->id, 'order_id' => $order->id, 'install_id' => $install->id, 'gid' => 'staged', 'sync_key' => 'incomplete', 'agreement_gid' => 'agreement', 'happened_at' => '2026-11-03 10:00:00', 'line_type' => 'PRODUCT', 'action_type' => 'ORDER', 'currency' => 'INR', 'amount' => '999', 'tax' => '0', 'discount' => '0', 'net' => '999']);
        $read = app(CommerceReadService::class);
        $oct = $read->report($store, ['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31']);
        $nov = $read->report($store, ['period' => 'custom', 'from' => '2026-11-01', 'to' => '2026-11-30']);
        $this->assertSame(1, $oct['orders']);
        $this->assertSame('100.125000', $oct['revenue'][0]['net_sales']);
        $this->assertSame(0, $nov['orders']);
        $this->assertCount(2, $nov['revenue']);
        $this->assertSame('-20.125000', $nov['revenue'][0]['net_sales']);
    }

    public function test_report_excludes_test_cancelled_deleted_orders_and_rejects_bad_ranges(): void
    {
        [$client] = $this->fixture();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->getJson("/api/clients/{$client->id}/shopify/report?period=custom&from=2026-11-10&to=2026-11-01")->assertUnprocessable();
    }

    public function test_webhook_verifies_signature_and_deduplicates_without_storing_customer_fields(): void
    {
        [, $app] = $this->fixture();
        $raw = json_encode(['id' => 123, 'email' => 'must-not-retain@example.test', 'customer' => ['name' => 'private']]);
        $headers = ['X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', $raw, $app->secret, true)), 'X-Shopify-Shop-Domain' => 'fixture.myshopify.com', 'X-Shopify-Topic' => 'orders/create', 'X-Shopify-Webhook-Id' => 'delivery-one', 'CONTENT_TYPE' => 'application/json'];
        $this->call('POST', '/api/shopify/webhooks/pilot', [], [], [], array_combine(array_map(fn ($k) => $k === 'CONTENT_TYPE' ? $k : 'HTTP_'.strtoupper(str_replace('-', '_', $k)), array_keys($headers)), array_values($headers)), $raw)->assertOk();
        $event = WebhookEvent::firstOrFail();
        $this->assertSame(['id' => 123], $event->payload);
        $this->call('POST', '/api/shopify/webhooks/pilot', [], [], [], array_combine(array_map(fn ($k) => $k === 'CONTENT_TYPE' ? $k : 'HTTP_'.strtoupper(str_replace('-', '_', $k)), array_keys($headers)), array_values($headers)), $raw)->assertOk();
        $this->assertDatabaseCount('shop_hooks', 1);
        $this->postJson('/api/shopify/webhooks/pilot', ['id' => 123])->assertUnauthorized();
    }

    public function test_privacy_request_is_durable_before_ack_and_survives_inbox_payload_cleanup(): void
    {
        [, $app, $store] = $this->fixture();
        $order = Order::create(['store_id' => $store->id, 'install_id' => $store->active_install_id, 'gid' => 'gid://shopify/Order/123', 'placed_at' => now(), 'source_updated_at' => now()]);
        $raw = json_encode(['shop_id' => 1, 'orders_to_redact' => [123]]);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $raw, $app->secret, true)), 'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $store->domain, 'HTTP_X_SHOPIFY_TOPIC' => 'customers/redact', 'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'privacy-durable'];
        $this->call('POST', '/api/shopify/webhooks/pilot', [], [], [], $server, $raw)->assertOk();
        $this->assertDatabaseHas('shop_privacy', ['request_key' => 'privacy-durable', 'status' => 'pending']);
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists('shopify/redactions.enc');
        $event = WebhookEvent::firstOrFail();
        $event->update(['payload' => null, 'status' => 'failed']);
        $this->artisan('shopify:retry-event', ['id' => $event->id])->assertSuccessful();
        (new \App\Shopify\Jobs\ProcessWebhook($event->id))->handle(app(\App\Shopify\SyncCoordinator::class));
        $this->assertDatabaseMissing('shop_orders', ['id' => $order->id]);
        $this->assertDatabaseHas('shop_privacy', ['request_key' => 'privacy-durable', 'status' => 'completed']);
        $this->assertSame('processed', $event->fresh()->status);
    }

    public function test_token_refresh_persists_both_tokens_and_expiries(): void
    {
        [,,,$install] = $this->fixture();
        $install->update(['expires_at' => now()->subMinute()]);
        Http::fake(['https://fixture.myshopify.com/admin/oauth/access_token' => Http::response(['access_token' => 'replacement', 'refresh_token' => 'replacement-refresh', 'expires_in' => 3600, 'refresh_token_expires_in' => 7776000])]);
        $this->assertSame('replacement', app(TokenProvider::class)->access($install));
        $this->assertSame('replacement-refresh', $install->fresh()->tokens['refresh_token']);
    }

    public function test_http_200_graphql_errors_are_not_empty_success(): void
    {
        [,,,$install] = $this->fixture();
        Http::fake(['*' => Http::response(['errors' => [['extensions' => ['code' => 'ACCESS_DENIED']]]])]);
        $this->expectException(ShopifyException::class);
        app(\App\Shopify\GraphqlClient::class)->query($install, 'query { shop { id } }');
    }

    public function test_paginated_fallback_does_not_refetch_the_first_page_forever(): void
    {
        [,,,$install] = $this->fixture();
        $run = SyncRun::create(['install_id' => $install->id, 'generation' => 1, 'kind' => 'history', 'until_at' => now(), 'stage' => 'import', 'status' => 'running', 'checkpoint' => ['mode' => 'cursor', 'page' => [], 'cursor' => null, 'has_next' => true, 'next_cursor' => 'page-two', 'filter' => 'test']]);
        app(SyncEngine::class)->step($run);
        $this->assertSame('page-two', $run->fresh()->checkpoint['cursor']);
        $this->assertArrayNotHasKey('page', $run->fresh()->checkpoint);
    }

    public function test_paged_order_import_commits_only_after_all_sales_pages(): void
    {
        [, ,$store,$install] = $this->fixture();
        $metadata = ['id' => 'gid://shopify/Order/90', 'updatedAt' => '2026-11-01T10:00:00Z', 'createdAt' => '2026-10-20T10:00:00Z', 'processedAt' => null, 'test' => false, 'cancelledAt' => null, 'displayFinancialStatus' => 'PENDING'];
        $sale = fn ($id, $amount, $tax, $action) => ['id' => $id, 'lineType' => 'PRODUCT', 'actionType' => $action, 'totalAmount' => ['shopMoney' => ['amount' => $amount, 'currencyCode' => 'INR']], 'totalTaxAmount' => ['shopMoney' => ['amount' => $tax, 'currencyCode' => 'INR']], 'totalDiscountAmountBeforeTaxes' => ['shopMoney' => ['amount' => '0', 'currencyCode' => 'INR']]];
        Http::fake(function ($request) use ($metadata, $sale) {
            if (str_contains($request['query'], 'OrderIdentity')) {
                return Http::response(['data' => ['order' => $metadata]]);
            }
            if (str_contains($request['query'], 'OrderAgreements')) {
                return Http::response(['data' => ['order' => ['agreements' => ['edges' => [['cursor' => 'agreement-end', 'node' => ['id' => 'agreement-one', 'happenedAt' => '2026-10-20T10:00:00Z']]], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'agreement-end']]]]]);
            }
            if (str_contains($request['query'], 'AgreementSales')) {
                $second = (((array) $request['variables'])['after'] ?? null) === 'sale-next';

                return Http::response(['data' => ['order' => ['agreements' => ['nodes' => [['sales' => ['nodes' => [$second ? $sale('return', '-12', '-2', 'RETURN') : $sale('purchase', '120', '20', 'ORDER')], 'pageInfo' => ['hasNextPage' => ! $second, 'endCursor' => $second ? 'done' : 'sale-next']]]]]]]]);
            }
            throw new \RuntimeException('Unexpected query');
        });
        $run = SyncRun::create(['install_id' => $install->id, 'generation' => 1, 'kind' => 'history', 'until_at' => now(), 'status' => 'running', 'stage' => 'order', 'checkpoint' => ['mode' => 'cursor', 'catching_up' => true, 'page' => [], 'has_next' => false, 'order_gid' => $metadata['id']]]);
        $engine = app(SyncEngine::class);
        for ($step = 0; $step < 15 && $run->fresh()->status !== 'completed'; $step++) {
            $engine->step($run->fresh());
        }
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(1, $run->fresh()->records);
        $this->assertSame(2, Sale::count());
        $report = app(CommerceReadService::class)->report($store->fresh(), ['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31']);
        $this->assertSame(1, $report['orders']);
        $this->assertSame('90.000000', $report['revenue'][0]['net_sales']);
    }

    public function test_customer_redaction_is_a_tombstone_against_later_reimport(): void
    {
        [,$app,$store,$install] = $this->fixture();
        $order = Order::create(['store_id' => $store->id, 'install_id' => $install->id, 'gid' => 'gid://shopify/Order/5', 'placed_at' => now(), 'source_updated_at' => now()]);
        $event = WebhookEvent::create(['app_id' => $app->id, 'install_id' => $install->id, 'generation' => 1, 'domain' => $store->domain, 'topic' => 'customers/redact', 'delivery_id' => 'privacy-one', 'payload' => ['orders_to_redact' => [5]]]);
        (new \App\Shopify\Jobs\ProcessWebhook($event->id))->handle(app(\App\Shopify\SyncCoordinator::class));
        $this->assertDatabaseMissing('shop_orders', ['id' => $order->id]);
        $run = SyncRun::create(['install_id' => $install->id, 'generation' => 1, 'kind' => 'history', 'until_at' => now(), 'stage' => 'order', 'checkpoint' => ['order_gid' => 'gid://shopify/Order/5']]);
        app(SyncEngine::class)->step($run);
        $this->assertSame('import', $run->fresh()->stage);
        Http::assertNothingSent();
    }

    public function test_order_counts_exclude_test_cancelled_and_deleted_but_keep_unpaid(): void
    {
        [,, $store,$install] = $this->fixture();
        foreach ([['test' => true], ['cancelled_at' => now()], ['deleted' => true], ['financial_status' => 'PENDING']] as $i => $flags) {
            Order::create($flags + ['store_id' => $store->id, 'install_id' => $install->id, 'gid' => 'gid://shopify/Order/'.$i, 'placed_at' => '2026-11-03T10:00:00Z', 'source_updated_at' => now()]);
        }
        $report = app(CommerceReadService::class)->report($store, ['period' => 'custom', 'from' => '2026-11-03', 'to' => '2026-11-03']);
        $this->assertSame(1, $report['orders']);
    }

    public function test_development_store_counts_test_orders_and_sales_today_without_changing_real_store_reports(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-04T15:00:00Z'));
        [,, $store, $install] = $this->fixture();
        $store->update(['timezone' => 'America/New_York']);
        $order = Order::create(['store_id' => $store->id, 'install_id' => $install->id, 'gid' => 'gid://shopify/Order/1000', 'placed_at' => now(), 'source_updated_at' => now(), 'test' => true, 'sales_key' => 'snapshot', 'sync_state' => 'ready']);
        Sale::create(['store_id' => $store->id, 'order_id' => $order->id, 'install_id' => $install->id, 'gid' => 'dev-sale', 'sync_key' => 'snapshot', 'agreement_gid' => 'agreement', 'happened_at' => now(), 'line_type' => 'PRODUCT', 'action_type' => 'ORDER', 'currency' => 'INR', 'amount' => '100', 'tax' => '0', 'discount' => '0', 'net' => '100']);
        $read = app(CommerceReadService::class);
        $production = $read->report($store->fresh(), ['period' => 'last_30_days']);
        $this->assertSame(0, $production['orders']);
        $this->assertSame([], $production['revenue']);

        $store->update(['is_development' => true]);
        $development = $read->report($store->fresh(), ['period' => 'last_30_days']);
        $this->assertSame(1, $development['orders']);
        $this->assertSame('100.000000', $development['revenue'][0]['net_sales']);
        $this->assertSame('2026-11-04', $development['range']['to']);
        $this->assertTrue($development['coverage']['test_orders_included']);
        $this->assertSame(1, $read->report($store->fresh(), ['period' => 'last_7_days'])['orders']);
        $this->assertSame(0, $read->report($store->fresh(), ['period' => 'yesterday'])['orders']);
    }

    public function test_recent_reporting_includes_real_orders_placed_today(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-04T15:00:00Z'));
        [,, $store, $install] = $this->fixture();
        Order::create(['store_id' => $store->id, 'install_id' => $install->id, 'gid' => 'gid://shopify/Order/1001', 'placed_at' => now(), 'source_updated_at' => now()]);
        $this->assertSame(1, app(CommerceReadService::class)->report($store, ['period' => 'last_30_days'])['orders']);
    }

    public function test_history_runs_are_deduplicated_and_bounded_to_sixty_days(): void
    {
        $this->freezeTime();
        [,,,$install] = $this->fixture();
        $sync = app(\App\Shopify\SyncCoordinator::class);
        $first = $sync->enqueue($install, 'history');
        $second = $sync->enqueue($install, 'history');
        $this->assertSame($first->id, $second->id);
        $this->assertSame(now()->subDays(60)->toDateTimeString(), $first->from_at->toDateTimeString());
        $first->update(['status' => 'failed']);
        $this->assertSame(now()->subDays(60)->toDateTimeString(), $sync->enqueue($install->fresh(), 'history')->from_at->toDateTimeString());
    }

    public function test_supported_sixty_day_history_does_not_display_an_approval_warning(): void
    {
        [,, $store] = $this->fixture();
        $store->update(['history_complete' => true, 'limited_history' => true, 'last_synced_at' => now()]);
        $report = app(CommerceReadService::class)->report($store->fresh(), []);
        $this->assertSame(60, $report['coverage']['window_days']);
        $this->assertSame([], $report['warnings']);
    }

    public function test_shipping_and_gift_cards_are_not_merchandise_revenue_and_unknown_types_fail(): void
    {
        [,, $store,$install] = $this->fixture();
        $order = new Order(['id' => 1, 'store_id' => $store->id]);
        $importer = app(OrderImporter::class);
        $sale = ['id' => 'sale', 'agreement_gid' => 'agreement', 'happened_at' => now(), 'lineType' => 'SHIPPING', 'actionType' => 'ORDER', 'totalAmount' => ['shopMoney' => ['amount' => '12', 'currencyCode' => 'KWD']], 'totalTaxAmount' => ['shopMoney' => ['amount' => '0.123', 'currencyCode' => 'KWD']]];
        $this->assertSame('0.000000', $importer->normalize($sale, $order, $install)['net']);
        $sale['lineType'] = 'GIFT_CARD';
        $this->assertSame('0.000000', $importer->normalize($sale, $order, $install)['net']);
        $sale['lineType'] = 'UNRECOGNIZED';
        $this->expectException(ShopifyException::class);
        $importer->normalize($sale, $order, $install);
    }

    public function test_redaction_ledger_survives_sql_tombstone_loss(): void
    {
        [, $app, $store, $install] = $this->fixture();
        $privacy = \App\Shopify\Models\PrivacyRequest::create(['app_id' => $app->id, 'domain' => $store->domain, 'request_key' => 'restore-request', 'topic' => 'customers/redact', 'order_gids' => ['gid://shopify/Order/55']]);
        $ledger = app(\App\Shopify\PrivacyLedger::class);
        $ledger->record($privacy);
        $privacy->delete();
        Order::create(['store_id' => $store->id, 'install_id' => $install->id, 'gid' => 'gid://shopify/Order/55', 'placed_at' => now(), 'source_updated_at' => now()]);
        $this->assertSame(1, $ledger->replay());
        $this->assertDatabaseMissing('shop_orders', ['gid' => 'gid://shopify/Order/55']);
        $this->assertDatabaseHas('shop_privacy', ['request_key' => 'restore-request', 'status' => 'completed']);
    }

    public function test_date_ranges_use_store_timezone_across_dst(): void
    {
        [, , $store, $install] = $this->fixture();
        $store->update(['timezone' => 'America/New_York']);
        foreach (['2026-03-08 04:59:59', '2026-03-08 05:00:00', '2026-03-09 03:59:59', '2026-03-09 04:00:00'] as $index => $time) {
            Order::create(['store_id' => $store->id, 'install_id' => $install->id, 'gid' => 'gid://shopify/Order/'.(100 + $index), 'placed_at' => $time, 'source_updated_at' => now()]);
        }
        $report = app(CommerceReadService::class)->report($store, ['period' => 'custom', 'from' => '2026-03-08', 'to' => '2026-03-08']);
        $this->assertSame(2, $report['orders']);
    }

    public function test_embedded_bearer_requests_bypass_agency_cookie_csrf_without_weakening_agency_routes(): void
    {
        config(['sanctum.stateful' => ['sharkads.brandshark.in']]);
        $embedded = \Illuminate\Http\Request::create('/api/shopify/embedded/session', 'POST', [], [], [], ['HTTP_REFERER' => 'https://sharkads.brandshark.in/shopify/app/pilot']);
        $agency = \Illuminate\Http\Request::create('/api/clients/1/shopify/link', 'POST', [], [], [], ['HTTP_REFERER' => 'https://sharkads.brandshark.in/clients/1']);
        $this->assertFalse(\App\Http\Middleware\AgencyStatefulRequests::fromFrontend($embedded));
        $this->assertTrue(\App\Http\Middleware\AgencyStatefulRequests::fromFrontend($agency));
    }
}
