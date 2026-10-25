<?php

namespace App\Jobs;

use App\Models\NotificationDelivery;
use App\Models\ReportRun;
use App\Services\AdLibraryAudit\SafePublicUrl;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class SendNotificationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $deliveryId)
    {
        $this->onQueue(config('workloads.queues.notifications', 'notifications'));
        $this->tries = config('workloads.policies.notifications.tries', 3);
        $this->backoff = config('workloads.policies.notifications.backoff', [30, 120, 600]);
    }

    public function uniqueId(): string
    {
        return 'notification:'.$this->deliveryId;
    }

    public function handle(SafePublicUrl $urls): void
    {
        $delivery = NotificationDelivery::with('destination')->findOrFail($this->deliveryId);
        if ($delivery->status === 'delivered') {
            return;
        }
        $destination = $delivery->destination;
        $config = $destination->configuration;
        $delivery->increment('attempts');
        try {
            if ($destination->channel === 'email') {
                Mail::raw((string) ($delivery->payload['text'] ?? json_encode($delivery->payload)), fn ($message) => $message->to($config['address'])->subject($delivery->payload['title'] ?? 'Pulse notification'));
            } else {
                $url = $urls->assert((string) ($config['url'] ?? ''));
                abort_unless(str_starts_with($url, 'https://'), 422, 'Notification webhooks must use HTTPS.');
                $payload = in_array($destination->channel, ['google_chat', 'slack', 'teams'], true) ? ['text' => $delivery->payload['text'] ?? $delivery->payload['title'] ?? 'Pulse notification'] : $delivery->payload;
                $request = Http::timeout(10)->acceptJson()->withOptions(['allow_redirects' => false]);
                if ($destination->channel === 'webhook' && filled($config['secret'] ?? null)) {
                    $request = $request->withHeaders(['X-Pulse-Signature' => hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $config['secret'])]);
                }
                $request->post($url, $payload)->throw();
            }
            $delivery->update(['status' => 'delivered', 'delivered_at' => now(), 'error_message' => null]);
            if ($reportRunId = $delivery->payload['report_run_id'] ?? null) {
                $run = ReportRun::find($reportRunId);
                if ($run && NotificationDelivery::where('event_type', 'report_delivery')->whereJsonContains('payload->report_run_id', (int) $reportRunId)->where('status', '!=', 'delivered')->doesntExist()) {
                    $run->update(['delivery_status' => 'delivered', 'delivered_at' => now(), 'delivery_error' => null]);
                }
            }
        } catch (\Throwable $error) {
            $delivery->update(['status' => 'failed', 'error_message' => mb_substr($error->getMessage(), 0, 1000)]);
            if ($reportRunId = $delivery->payload['report_run_id'] ?? null) {
                ReportRun::whereKey($reportRunId)->update(['delivery_status' => 'failed', 'delivery_error' => mb_substr($error->getMessage(), 0, 1000)]);
            }
            throw $error;
        }
    }
}
