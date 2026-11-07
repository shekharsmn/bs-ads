<?php

namespace App\Shopify\Console;

use App\Shopify\Jobs\ProcessWebhook;
use App\Shopify\Models\WebhookEvent;
use Illuminate\Console\Command;

final class RetryWebhook extends Command
{
    protected $signature = 'shopify:retry-event {id}';

    protected $description = 'Replay one retained Shopify inbox event; never retry other application queues';

    public function handle(): int
    {
        $event = WebhookEvent::find($this->argument('id'));
        if (! $event) {
            $this->error('Shopify event not found.');

            return self::FAILURE;
        }
        if ($event->status === 'processed') {
            $this->info('Already processed.');

            return self::SUCCESS;
        }
        if (! $event->payload && ! in_array($event->topic, ['customers/data_request', 'customers/redact', 'shop/redact'], true)) {
            $this->error('Payload retention expired. Use API reconciliation instead.');

            return self::FAILURE;
        }
        $event->update(['status' => 'pending', 'error_code' => null]);
        ProcessWebhook::dispatch($event->id);
        $this->info('Shopify event queued.');

        return self::SUCCESS;
    }
}
