<?php

namespace App\Shopify;

use App\Shopify\Models\Installation;
use App\Shopify\Models\Order;
use App\Shopify\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class OrderImporter
{
    public function __construct(private readonly GraphqlClient $api) {}

    /** Metadata and a fully paginated sales snapshot are committed separately by the coordinator. */
    public function persist(Installation $install, array $order, int $runId): Order
    {
        return DB::transaction(function () use ($install, $order, $runId) {
            $install->refresh();
            if ($install->status !== 'active') {
                throw new ShopifyException('installation_inactive');
            }
            $row = Order::where('store_id', $install->store_id)->where('gid', $order['id'])->lockForUpdate()->first();
            $version = CarbonImmutable::parse($order['updatedAt']);
            if ($row && $row->source_updated_at->gt($version)) {
                return $row;
            }
            if ($row && $row->sales_key) {
                // Keep the canonical metadata and acquisition lineage until the new sales snapshot commits.
                $row->update(['run_id' => $runId]);

                return $row;
            }
            $row ??= new Order(['store_id' => $install->store_id, 'gid' => $order['id']]);
            $row->fill(['install_id' => $install->id, 'run_id' => $runId, 'placed_at' => $order['processedAt'] ?? $order['createdAt'],
                'source_updated_at' => $version, 'test' => (bool) $order['test'], 'cancelled_at' => $order['cancelledAt'] ?? null,
                'financial_status' => $order['displayFinancialStatus'] ?? null, 'deleted' => false, 'sync_state' => 'pending'])->save();

            return $row;
        });
    }

    public function normalize(array $sale, Order $order, Installation $install): array
    {
        $line = $sale['lineType'];
        $action = $sale['actionType'];
        if (! in_array($line, ['PRODUCT', 'SHIPPING', 'TIP', 'GIFT_CARD', 'DUTY', 'ADDITIONAL_FEE', 'FEE', 'ADJUSTMENT'], true) || ! in_array($action, ['ORDER', 'RETURN', 'UPDATE'], true)) {
            throw new ShopifyException('unsupported_activity');
        }
        $money = $sale['totalAmount']['shopMoney'];
        $tax = $sale['totalTaxAmount']['shopMoney'];
        if ($money['currencyCode'] !== $tax['currencyCode'] || ! preg_match('/^-?\d{1,18}(\.\d{1,6})?$/D', $money['amount']) || ! preg_match('/^-?\d{1,18}(\.\d{1,6})?$/D', $tax['amount'])) {
            throw new ShopifyException('invalid_money');
        }
        // Sale amounts already carry Shopify's signs. Never negate RETURN a second time.
        $net = $line === 'PRODUCT' ? bcsub($money['amount'], $tax['amount'], 6) : '0.000000';

        return ['store_id' => $order->store_id, 'order_id' => $order->id, 'install_id' => $install->id, 'gid' => $sale['id'],
            'agreement_gid' => $sale['agreement_gid'], 'happened_at' => $sale['happened_at'], 'line_type' => $line, 'action_type' => $action,
            'currency' => $money['currencyCode'], 'amount' => $money['amount'], 'tax' => $tax['amount'],
            'discount' => data_get($sale, 'totalDiscountAmountBeforeTaxes.shopMoney.amount', '0'), 'net' => $net];
    }
}
