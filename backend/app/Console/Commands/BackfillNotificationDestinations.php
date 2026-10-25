<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\NotificationDestination;
use Illuminate\Console\Command;

class BackfillNotificationDestinations extends Command
{
    protected $signature = 'notifications:backfill-google-chat';

    protected $description = 'Copy legacy client Google Chat webhooks into normalized notification destinations';

    public function handle(): int
    {
        Client::whereNotNull('google_chat_webhook')->orderBy('id')->chunkById(100, function ($clients) {
            foreach ($clients as $client) {
                $destination = NotificationDestination::firstOrCreate(
                    ['client_id' => $client->id, 'channel' => 'google_chat', 'name' => 'Google Chat (migrated)'],
                    ['configuration' => ['url' => $client->google_chat_webhook], 'enabled' => true]
                );
                foreach (['alert', 'report_delivery', 'workflow_failure'] as $event) {
                    $destination->subscriptions()->firstOrCreate(['event_type' => $event]);
                }
            }
        });

        return self::SUCCESS;
    }
}
