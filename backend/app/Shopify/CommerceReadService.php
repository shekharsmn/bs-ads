<?php

namespace App\Shopify;

use App\Shopify\Models\Order;
use App\Shopify\Models\Sale;
use App\Shopify\Models\Store;
use App\Shopify\Models\SyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

final class CommerceReadService
{
    public function report(Store $store, array $input): array
    {
        $data = Validator::make($input, ['period' => 'sometimes|in:yesterday,last_7_days,month_to_date,last_30_days,all,custom', 'from' => 'required_if:period,custom|date_format:Y-m-d', 'to' => 'required_if:period,custom|date_format:Y-m-d|after_or_equal:from'])->validate();
        $today = CarbonImmutable::now($store->timezone)->startOfDay();
        $period = $data['period'] ?? 'last_30_days';
        $end = $period === 'yesterday' ? $today->subDay() : $today;
        $start = match ($period) {
            'yesterday' => $end, 'last_7_days' => $end->subDays(6), 'month_to_date' => $today->startOfMonth(),
            'all' => $store->history_from?->setTimezone($store->timezone)->startOfDay() ?? $today,
            default => $end->subDays(29),
        };
        if (in_array($period, ['month_to_date', 'all'], true)) {
            $end = $today;
        }
        if ($period === 'custom') {
            $start = CarbonImmutable::parse($data['from'], $store->timezone)->startOfDay();
            $end = CarbonImmutable::parse($data['to'], $store->timezone)->startOfDay();
        }
        if ($start->gt($end)) {
            $start = $end;
        }
        $from = $start->utc();
        $until = $end->addDay()->utc();
        $includeTest = (bool) $store->is_development;
        $orders = Order::where('store_id', $store->id)->when(! $includeTest, fn ($q) => $q->where('test', false))->where('deleted', false)->whereNull('cancelled_at')->where('placed_at', '>=', $from)->where('placed_at', '<', $until)->count();
        $blocks = [];
        $trend = [];
        // SQL sums remain decimal strings. Group dates in the store timezone in PHP, not the database server timezone.
        Sale::where('store_id', $store->id)->where('happened_at', '>=', $from)->where('happened_at', '<', $until)
            ->whereHas('order', fn ($q) => $q->when(! $includeTest, fn ($query) => $query->where('test', false))->where('deleted', false)->whereColumn('shop_orders.sales_key', 'shop_sales.sync_key'))->orderBy('id')->chunkById(500, function ($sales) use (&$blocks, &$trend, $store) {
                foreach ($sales as $sale) {
                    $currency = $sale->currency;
                    $day = $sale->happened_at->setTimezone($store->timezone)->toDateString();
                    $blocks[$currency] = bcadd($blocks[$currency] ?? '0', $sale->net, 6);
                    $trend[$day][$currency] = bcadd($trend[$day][$currency] ?? '0', $sale->net, 6);
                }
            });
        $install = $store->installation;
        $run = $install ? SyncRun::where('install_id', $install->id)->latest('id')->first() : null;
        $warnings = [];
        if (! $store->history_complete) {
            $warnings[] = 'Historical import is incomplete.';
        }
        if ($store->history_from && $from->lt($store->history_from)) {
            $warnings[] = 'The selected range begins before verified coverage.';
        }
        if (! $store->last_synced_at || $store->last_synced_at->lt(now()->subMinutes(30))) {
            $warnings[] = 'Store data is awaiting synchronization or stale.';
        }
        if ($install?->status !== 'active') {
            $warnings[] = 'Store authorization requires attention.';
        }
        if ($run?->status === 'failed') {
            $warnings[] = 'The last synchronization failed: '.$run->error_code;
        }
        if (Order::where('store_id', $store->id)->where('sync_state', 'unsupported')->exists()) {
            $warnings[] = 'Some financial activity is unsupported; revenue coverage is incomplete.';
        }
        if (Sale::where('store_id', $store->id)->where('line_type', 'ADJUSTMENT')->whereHas('order', fn ($q) => $q->whereColumn('shop_orders.sales_key', 'shop_sales.sync_key'))->exists()) {
            $warnings[] = 'Non-product financial adjustments are retained separately and excluded from merchandise net sales.';
        }
        ksort($trend);

        return ['store' => $store->only(['id', 'name', 'domain', 'timezone', 'currency', 'client_id', 'is_development']), 'orders' => $orders,
            'revenue' => array_map(fn ($currency, $amount) => ['currency' => $currency, 'net_sales' => $amount], array_keys($blocks), array_values($blocks)),
            'trend' => array_map(fn ($date, $currencies) => ['date' => $date, 'revenue' => $currencies], array_keys($trend), array_values($trend)),
            'range' => ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'timezone' => $store->timezone],
            'definitions' => ['revenue' => 'Net merchandise sales after discounts and reversals, excluding taxes and shipping; adjustments use their activity date.',
                'orders' => ($includeTest ? 'Development-store test orders are included. ' : 'Test orders are excluded. ').'Non-cancelled placed orders include unpaid/COD. Refunded orders still count unless cancelled or deleted.'],
            'coverage' => ['from' => $store->history_from?->toIso8601String(), 'complete' => $store->history_complete, 'limited_history' => $store->limited_history, 'window_days' => 60, 'test_orders_included' => $includeTest, 'last_synced_at' => $store->last_synced_at?->toIso8601String()],
            'connection' => ['status' => $install?->status ?? 'disconnected', 'run' => $run?->only(['id', 'kind', 'status', 'stage', 'records', 'error_code'])], 'warnings' => $warnings];
    }
}
