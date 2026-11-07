<?php

namespace App\Shopify\Jobs;

use App\Shopify\Models\Installation;
use App\Shopify\Models\Order;
use App\Shopify\Models\PrivacyRequest;
use App\Shopify\Models\Sale;
use App\Shopify\Models\WebhookEvent;
use App\Shopify\ShopifyException;
use App\Shopify\SyncCoordinator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class ProcessWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 90;

    public function __construct(public readonly int $eventId)
    {
        $this->onQueue(config('shopify.queue'));
    }

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(SyncCoordinator $sync): void
    {
        $event = WebhookEvent::find($this->eventId);
        if (! $event || $event->status === 'processed') {
            return;
        }
        $install = $event->install_id ? Installation::find($event->install_id) : null;
        $lock = Cache::lock('shop-process:'.($install?->store_id ?? $event->domain), 110);
        if (! $lock->get()) {
            $this->release(10);

            return;
        }
        try {
            $event->refresh();
            if ($event->status === 'processed') {
                return;
            }
            $install?->refresh();
            $data = $event->payload ?? [];
            $topic = $event->topic;
            if (in_array($topic, ['customers/data_request', 'customers/redact', 'shop/redact'], true)) {
                $ids = $data['orders_to_redact'] ?? $data['orders_requested'] ?? [];
                $gids = array_map(fn ($id) => 'gid://shopify/Order/'.$id, $ids);
                $ledger = app(\App\Shopify\PrivacyLedger::class);
                $privacy = DB::transaction(function () use ($event, $topic, $gids, $ledger) {
                    $privacy = PrivacyRequest::firstOrCreate(['app_id' => $event->app_id, 'request_key' => $event->delivery_id], ['domain' => $event->domain, 'topic' => $topic, 'order_gids' => $gids]);
                    if ($privacy->wasRecentlyCreated && $topic !== 'customers/data_request') {
                        $ledger->record($privacy);
                    }

                    return $privacy;
                });
                $gids = $privacy->order_gids ?? $gids;
                if ($privacy->status !== 'completed') {
                    if ($topic !== 'customers/data_request') {
                        $ledger->erase($privacy);
                    } elseif ($install) {
                        $orders = Order::where('store_id', $install->store_id)->where('install_id', $install->id)->whereIn('gid', $gids);
                        $path = 'shopify/privacy-'.$privacy->id.'.enc';
                        $export = ['orders' => $orders->get()->toArray(), 'sales' => Sale::whereIn('order_id', $orders->select('id'))->get()->toArray()];
                        if (! Storage::disk('local')->put($path, encrypt(json_encode($export, JSON_THROW_ON_ERROR)))) {
                            throw new ShopifyException('privacy_storage_failed', true);
                        }
                        $privacy->update(['status' => 'export_ready']);
                    } else {
                        $privacy->update(['status' => 'completed', 'completed_at' => now()]);
                    }
                }
            } elseif ($install && $install->generation === $event->generation && $install->status === 'active') {
                if ($topic === 'orders/delete') {
                    Order::where('store_id', $install->store_id)->where('gid', 'gid://shopify/Order/'.($data['id'] ?? ''))->update(['deleted' => true]);
                } elseif ($topic === 'app/scopes_update') {
                    $install->update(['scopes' => $data['current'] ?? []]);
                } elseif (str_starts_with($topic, 'orders/') || $topic === 'refunds/create') {
                    // The API pipeline reconciles authoritative state, not webhook monetary snapshots.
                    $sync->enqueue($install);
                }
            }
            $event->update(['status' => 'processed', 'error_code' => null]);
        } catch (ShopifyException $e) {
            if ($e->retryable) {
                throw $e;
            }
            $event->update(['status' => 'failed', 'error_code' => $e->errorCode]);
        } finally {
            $lock->release();
        }
    }

    public function failed(?\Throwable $e): void
    {
        WebhookEvent::whereKey($this->eventId)->update(['status' => 'failed', 'error_code' => 'processing_failed']);
    }
}
