<?php

namespace App\Services;

use App\Jobs\SendNotificationJob;
use App\Models\NotificationDelivery;
use App\Models\NotificationDestination;

class NotificationDispatcher
{
    public function dispatch(int $clientId, string $eventType, array $payload, string $eventId): int
    {
        $destinations = NotificationDestination::where('client_id', $clientId)->where('enabled', true)
            ->whereHas('subscriptions', fn ($query) => $query->where('event_type', $eventType))->get();
        foreach ($destinations as $destination) {
            $delivery = NotificationDelivery::firstOrCreate(
                ['idempotency_key' => $eventType.':'.$eventId.':'.$destination->id],
                ['notification_destination_id' => $destination->id, 'event_type' => $eventType, 'payload' => $payload]
            );
            if ($delivery->wasRecentlyCreated) {
                SendNotificationJob::dispatch($delivery->id);
            }
        }

        return $destinations->count();
    }
}
