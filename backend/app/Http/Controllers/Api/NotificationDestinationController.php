<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendNotificationJob;
use App\Models\NotificationDelivery;
use App\Models\NotificationDestination;
use App\Services\AuditTrail;
use App\Services\ClientAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class NotificationDestinationController extends Controller
{
    private const CHANNELS = ['google_chat', 'email', 'slack', 'teams', 'webhook'];

    private const EVENTS = ['alert', 'data_quality', 'report_delivery', 'workflow_failure', 'ai_budget'];

    public function index(Request $request)
    {
        $clientIds = ClientAccess::clientsFor($request->user())->pluck('id');

        return NotificationDestination::with(['subscriptions:id,notification_destination_id,event_type', 'client:id,name'])
            ->with(['deliveries' => fn ($q) => $q->latest()->limit(10)->select('id', 'notification_destination_id', 'event_type', 'status', 'attempts', 'error_message', 'delivered_at', 'created_at')])
            ->whereIn('client_id', $clientIds)->orderBy('name')->get();
    }

    public function store(Request $request, AuditTrail $audit)
    {
        $data = $this->validated($request);
        ClientAccess::authorize($request->user(), $data['client_id'], true);
        $destination = NotificationDestination::create([
            'client_id' => $data['client_id'], 'name' => $data['name'], 'channel' => $data['channel'],
            'configuration' => $data['configuration'], 'enabled' => $data['enabled'] ?? true, 'created_by' => $request->user()->id,
        ]);
        $destination->subscriptions()->createMany(collect($data['events'])->map(fn ($event) => ['event_type' => $event])->all());
        $audit->record('notification.destination_created', $destination, [], $destination->only(['name', 'channel', 'enabled']), $destination->client_id, $request->user(), $request);

        return response()->json($destination->load('subscriptions'), 201);
    }

    public function update(Request $request, NotificationDestination $destination, AuditTrail $audit)
    {
        ClientAccess::authorize($request->user(), $destination->client_id, true);
        $data = $this->validated($request, true);
        $before = $destination->only(['name', 'channel', 'enabled']);
        $destination->fill(collect($data)->only(['name', 'channel', 'enabled'])->all());
        if (! empty($data['configuration'])) {
            $destination->configuration = [...($destination->configuration ?? []), ...$data['configuration']];
        }
        $destination->save();
        if (isset($data['events'])) {
            $destination->subscriptions()->delete();
            $destination->subscriptions()->createMany(collect($data['events'])->map(fn ($event) => ['event_type' => $event])->all());
        }
        $audit->record('notification.destination_updated', $destination, $before, $destination->only(['name', 'channel', 'enabled']), $destination->client_id, $request->user(), $request);

        return $destination->fresh('subscriptions');
    }

    public function destroy(Request $request, NotificationDestination $destination, AuditTrail $audit)
    {
        ClientAccess::authorize($request->user(), $destination->client_id, true);
        $audit->record('notification.destination_deleted', $destination, $destination->only(['name', 'channel', 'enabled']), [], $destination->client_id, $request->user(), $request);
        $destination->delete();

        return response()->noContent();
    }

    public function test(Request $request, NotificationDestination $destination)
    {
        ClientAccess::authorize($request->user(), $destination->client_id, true);
        $delivery = NotificationDelivery::create(['notification_destination_id' => $destination->id, 'event_type' => 'test', 'idempotency_key' => 'test:'.Str::uuid(), 'payload' => ['title' => 'SharkAds test notification', 'text' => 'This destination is connected to SharkAds.']]);
        try {
            SendNotificationJob::dispatchSync($delivery->id);
            $destination->update(['last_tested_at' => now(), 'last_test_status' => 'delivered', 'last_error' => null]);
        } catch (\Throwable $error) {
            $destination->update(['last_tested_at' => now(), 'last_test_status' => 'failed', 'last_error' => mb_substr($error->getMessage(), 0, 1000)]);
            throw $error;
        }

        return ['message' => 'Test notification delivered.'];
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $prefix = $partial ? 'sometimes|' : '';

        return $request->validate([
            'client_id' => $partial ? 'prohibited' : 'required|integer|exists:clients,id',
            'name' => $prefix.'required|string|max:120', 'channel' => $prefix.'required|in:'.implode(',', self::CHANNELS),
            'configuration' => $partial ? 'sometimes|array' : 'required|array',
            'configuration.url' => 'nullable|url|starts_with:https://', 'configuration.address' => 'nullable|email', 'configuration.secret' => 'nullable|string|max:255',
            'events' => $partial ? 'sometimes|array' : 'required|array', 'events.*' => 'required|in:'.implode(',', self::EVENTS), 'enabled' => 'sometimes|boolean',
        ]);
    }
}
