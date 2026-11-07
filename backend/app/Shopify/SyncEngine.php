<?php

namespace App\Shopify;

use App\Shopify\Models\Order;
use App\Shopify\Models\PrivacyRequest;
use App\Shopify\Models\Sale;
use App\Shopify\Models\SyncRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** One bounded API page or import operation per queue execution. */
final class SyncEngine
{
    public function __construct(private readonly GraphqlClient $api, private readonly OrderImporter $orders, private readonly SyncCoordinator $coordinator) {}

    public function step(SyncRun $run): int
    {
        $install = $run->installation;
        $cp = $run->checkpoint ?? [];
        $stage = $run->stage;
        $run->update(['status' => 'running']);
        if (! $install->app->data_approved) {
            throw new ShopifyException('data_approval_required');
        }
        if (! in_array('read_orders', $install->scopes ?? [], true)) {
            throw new ShopifyException('orders_scope_required');
        }
        // A redaction can arrive after the order has already reached a later import stage.
        if (isset($cp['order_gid']) && PrivacyRequest::where('domain', $install->store->domain)
            ->where('topic', 'customers/redact')->where('status', 'completed')->whereJsonContains('order_gids', $cp['order_gid'])->exists()) {
            $this->finishCheckpointOrder($cp);
            $run->update(['stage' => 'import', 'checkpoint' => $cp]);

            return 0;
        }
        if ($stage === 'start') {
            $shop = $this->api->query($install, 'query StoreMetadata { shop { id name ianaTimezone currencyCode plan { partnerDevelopment } } }')['shop'] ?? [];
            if (($shop['id'] ?? null) !== $install->store->gid) {
                throw new ShopifyException('shop_identity_mismatch');
            }
            try {
                new \DateTimeZone($shop['ianaTimezone']);
            } catch (\Throwable) {
                throw new ShopifyException('invalid_shop_timezone');
            }
            $install->store->update(['name' => $shop['name'], 'timezone' => $shop['ianaTimezone'], 'currency' => $shop['currencyCode'], 'is_development' => data_get($shop, 'plan.partnerDevelopment') === true]);
            $filter = 'created_at:>='.$run->until_at->subDays(60)->toIso8601String().' '.($run->from_at ? 'updated_at:>='.$run->from_at->toIso8601String().' ' : '').'updated_at:<'.$run->until_at->toIso8601String();
            $query = '{ orders(query:'.json_encode($filter).') { edges { node { id updatedAt } } } }';
            $data = $this->api->query($install, 'mutation Export($query: String!) { bulkOperationRunQuery(query: $query) { bulkOperation { id } userErrors { field message } } }', ['query' => $query]);
            if (! empty($data['bulkOperationRunQuery']['userErrors'])) {
                // A simpler cursor-based order list is a supported, resumable fallback.
                $cp = ['mode' => 'cursor', 'cursor' => null, 'filter' => $filter];
                $stage = 'import';
            } else {
                $id = data_get($data, 'bulkOperationRunQuery.bulkOperation.id');
                if (! $id) {
                    throw new ShopifyException('export_not_started');
                }
                $run->operation_gid = $id;
                $stage = 'poll';
            }
        } elseif ($stage === 'poll') {
            $operation = $this->api->query($install, 'query Operation($id: ID!) { node(id: $id) { ... on BulkOperation { id status url objectCount } } }', ['id' => $run->operation_gid])['node'] ?? [];
            if (in_array($operation['status'] ?? '', ['FAILED', 'CANCELED', 'EXPIRED'], true)) {
                throw new ShopifyException('export_failed');
            }
            if (in_array($operation['status'] ?? '', ['CREATED', 'RUNNING', 'CANCELING'], true)) {
                return 15;
            }
            if (($operation['status'] ?? '') !== 'COMPLETED') {
                throw new ShopifyException('export_status_unavailable', true);
            }
            if (! ctype_digit((string) ($operation['objectCount'] ?? ''))) {
                throw new ShopifyException('export_count_unavailable', true);
            }
            $cp['expected_rows'] = (int) $operation['objectCount'];
            if (empty($operation['url'])) {
                if ($cp['expected_rows'] > 0) {
                    throw new ShopifyException('export_url_unavailable', true);
                }
                $stage = 'catchup';
            } else {
                $cp['url'] = $operation['url'];
                $stage = 'download';
            }
        } elseif ($stage === 'download') {
            $url = $cp['url'];
            $host = parse_url($url, PHP_URL_HOST);
            if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! in_array($host, ['storage.googleapis.com'], true)) {
                throw new ShopifyException('untrusted_export_url');
            }
            $path = $this->path($run);
            Storage::disk('local')->makeDirectory('shopify');
            $temp = $path.'.tmp';
            try {
                $response = Http::connectTimeout(5)->timeout(60)->withOptions(['allow_redirects' => false, 'sink' => $temp,
                    'progress' => static function ($total, $downloaded) {
                        if ($total > 536870912 || $downloaded > 536870912) {
                            throw new ShopifyException('export_too_large');
                        }
                    }])->get($url);
                if (! $response->successful()) {
                    throw new ShopifyException('export_download_failed', true);
                }
                if (! rename($temp, $path)) {
                    throw new ShopifyException('export_storage_failed', true);
                }
            } catch (\Illuminate\Http\Client\ConnectionException) {
                throw new ShopifyException('export_download_failed', true);
            } finally {
                if (is_file($temp)) {
                    unlink($temp);
                }
            }
            unset($cp['url']);
            $cp['offset'] = 0;
            $cp['read_rows'] = 0;
            $stage = 'import';
        } elseif ($stage === 'import') {
            if (($cp['mode'] ?? '') === 'cursor') {
                if (! isset($cp['page'])) {
                    $connection = $this->api->query($install, 'query Orders($filter: String!, $after: String) { orders(first: 50, query: $filter, after: $after, sortKey: UPDATED_AT) { nodes { id updatedAt } pageInfo { hasNextPage endCursor } } }', ['filter' => $cp['filter'], 'after' => $cp['cursor'] ?? null])['orders'];
                    $cp['page'] = $connection['nodes'];
                    $cp['has_next'] = $connection['pageInfo']['hasNextPage'];
                    $cp['next_cursor'] = $connection['pageInfo']['endCursor'];
                }
                if ($cp['page']) {
                    $cp['order_gid'] = array_shift($cp['page'])['id'];
                    $stage = 'order';
                } elseif ($cp['has_next']) {
                    $cp['cursor'] = $cp['next_cursor'];
                    unset($cp['page']);
                } else {
                    $stage = $cp['catching_up'] ?? false ? 'complete' : 'catchup';
                }
            } else {
                if (! is_file($this->path($run))) {
                    $run->update(['stage' => 'start', 'checkpoint' => ['mode' => 'bulk']]);

                    return 1;
                }
                $handle = fopen($this->path($run), 'rb');
                if (! $handle) {
                    throw new ShopifyException('export_file_missing');
                }
                try {
                    fseek($handle, $cp['offset'] ?? 0);
                    $line = fgets($handle, 1048577);
                    if ($line === false) {
                        if (! feof($handle) || (isset($cp['expected_rows']) && $cp['expected_rows'] !== ($cp['read_rows'] ?? 0))) {
                            throw new ShopifyException('export_incomplete');
                        }
                        $stage = 'catchup';
                    } else {
                        if (strlen($line) > 1048576) {
                            throw new ShopifyException('export_record_too_large');
                        }
                        $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                        if (! preg_match('~^gid://shopify/Order/\d+$~', $record['id'] ?? '')) {
                            throw new ShopifyException('invalid_export_record');
                        }
                        $cp['order_gid'] = $record['id'];
                        $cp['next_offset'] = ftell($handle);
                        $cp['read_rows'] = ($cp['read_rows'] ?? 0) + 1;
                        $stage = 'order';
                    }
                } finally {
                    fclose($handle);
                }
            }
        } elseif ($stage === 'order') {
            $order = $this->api->query($install, file_get_contents(resource_path('shopify/2026-10/order.graphql')), ['id' => $cp['order_gid']])['order'] ?? null;
            if (! $order) {
                throw new ShopifyException('order_unavailable');
            }
            $row = $this->orders->persist($install, $order, $run->id);
            $cp['order'] = $order;
            $cp['order_id'] = $row->id;
            $cp['snapshot'] = (string) Str::uuid();
            $cp['agreement_cursor'] = null;
            $stage = 'agreements';
        } elseif ($stage === 'agreements') {
            $connection = $this->api->query($install, file_get_contents(resource_path('shopify/2026-10/agreements.graphql')), ['id' => $cp['order_gid'], 'after' => $cp['agreement_cursor']])['order']['agreements'] ?? null;
            if (! $connection) {
                throw new ShopifyException('agreements_unavailable');
            }
            $previous = $cp['agreement_cursor'];
            $cp['agreements'] = [];
            foreach ($connection['edges'] as $edge) {
                $cp['agreements'][] = $edge['node'] + ['before' => $previous];
                $previous = $edge['cursor'];
            }
            $cp['agreements_next'] = $connection['pageInfo']['hasNextPage'];
            $cp['agreement_cursor'] = $connection['pageInfo']['endCursor'];
            $cp['sale_cursor'] = null;
            $stage = 'sales';
        } elseif ($stage === 'sales') {
            if (empty($cp['agreements'])) {
                $stage = $cp['agreements_next'] ? 'agreements' : 'finish_order';
            } else {
                $agreement = $cp['agreements'][0];
                $connection = $this->api->query($install, file_get_contents(resource_path('shopify/2026-10/sales.graphql')), ['id' => $cp['order_gid'], 'agreementAfter' => $agreement['before'], 'after' => $cp['sale_cursor']])['order']['agreements']['nodes'][0]['sales'] ?? null;
                if (! $connection) {
                    throw new ShopifyException('sales_unavailable');
                }
                $row = Order::findOrFail($cp['order_id']);
                foreach ($connection['nodes'] as $sale) {
                    try {
                        $attributes = $this->orders->normalize($sale + ['agreement_gid' => $agreement['id'], 'happened_at' => $agreement['happenedAt']], $row, $install);
                    } catch (ShopifyException $e) {
                        $row->update(['sync_state' => 'unsupported']);
                        throw $e;
                    }
                    Sale::updateOrCreate(['order_id' => $row->id, 'sync_key' => $cp['snapshot'], 'gid' => $sale['id']], $attributes);
                }
                if ($connection['pageInfo']['hasNextPage']) {
                    $cp['sale_cursor'] = $connection['pageInfo']['endCursor'];
                } else {
                    array_shift($cp['agreements']);
                    $cp['sale_cursor'] = null;
                }
            }
        } elseif ($stage === 'finish_order') {
            $latest = $this->api->query($install, file_get_contents(resource_path('shopify/2026-10/order.graphql')), ['id' => $cp['order_gid']])['order'] ?? null;
            if (! $latest) {
                throw new ShopifyException('order_unavailable');
            }
            if ($latest['updatedAt'] !== $cp['order']['updatedAt']) {
                Sale::where('order_id', $cp['order_id'])->where('sync_key', $cp['snapshot'])->delete();
                $stage = 'order';
            } else {
                DB::transaction(function () use ($cp, $run, $install) {
                    $row = Order::whereKey($cp['order_id'])->lockForUpdate()->firstOrFail();
                    $freshInstall = $install->fresh();
                    if ($freshInstall->status !== 'active' || $freshInstall->generation !== $run->generation) {
                        throw new ShopifyException('installation_changed');
                    }
                    if ($row->source_updated_at->gt(\Carbon\CarbonImmutable::parse($cp['order']['updatedAt']))) {
                        throw new ShopifyException('order_changed', true);
                    }
                    $row->update(['sales_key' => $cp['snapshot'], 'sync_state' => 'ready', 'install_id' => $install->id,
                        'placed_at' => $cp['order']['processedAt'] ?? $cp['order']['createdAt'],
                        'source_updated_at' => $cp['order']['updatedAt'], 'test' => (bool) $cp['order']['test'],
                        'cancelled_at' => $cp['order']['cancelledAt'] ?? null, 'deleted' => false,
                        'financial_status' => $cp['order']['displayFinancialStatus'] ?? null]);
                    Sale::where('order_id', $row->id)->where('sync_key', '!=', $cp['snapshot'])->delete();
                });
                $run->increment('records');
                $this->finishCheckpointOrder($cp);
                $stage = 'import';
            }
        } elseif ($stage === 'catchup') {
            if (is_file($this->path($run))) {
                unlink($this->path($run));
            }
            // Upper bound advances once; repeated jobs do not chase an ever-moving window.
            $from = $run->until_at->subMinutes(5);
            $until = now();
            $cp = ['mode' => 'cursor', 'catching_up' => true, 'cursor' => null, 'filter' => 'created_at:>='.$until->subDays(60)->toIso8601String().' updated_at:>='.$from->toIso8601String().' updated_at:<'.$until->toIso8601String()];
            $run->until_at = $until;
            $stage = 'import';
        } elseif ($stage === 'complete') {
            $this->coordinator->complete($run);

            return 0;
        }
        $run->update(['stage' => $stage, 'checkpoint' => $cp]);

        return 0;
    }

    private function finishCheckpointOrder(array &$checkpoint): void
    {
        if (isset($checkpoint['next_offset'])) {
            $checkpoint['offset'] = $checkpoint['next_offset'];
        }
        foreach (['order', 'order_gid', 'order_id', 'snapshot', 'agreements', 'agreements_next', 'agreement_cursor', 'sale_cursor', 'next_offset'] as $key) {
            unset($checkpoint[$key]);
        }
    }

    private function path(SyncRun $run): string
    {
        return Storage::disk('local')->path('shopify/run-'.$run->id.'.jsonl');
    }
}
