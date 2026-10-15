<?php

namespace App\Services;

use App\Models\AlertEvent;
use App\Models\AlertIncident;
use App\Models\AlertRule;
use App\Models\AdAccount;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IncidentService
{
    public function __construct(private readonly GoogleChatNotifier $notifier) {}

    public function trigger(AlertRule $rule, AdAccount $account, ?Campaign $campaign, array $result): AlertIncident
    {
        $scopeKey = $campaign ? 'campaign:'.$campaign->id : 'account:'.$account->id;
        $incident = DB::transaction(function () use ($rule, $account, $campaign, $result, $scopeKey) {
            $incident = AlertIncident::query()->where('alert_rule_id', $rule->id)->where('ad_account_id', $account->id)
                ->when($campaign, fn ($q) => $q->where('campaign_id', $campaign->id), fn ($q) => $q->whereNull('campaign_id'))
                ->whereIn('status', ['open', 'acknowledged', 'snoozed'])->lockForUpdate()->first();
            if (! $incident) {
                $incident = AlertIncident::create([
                'incident_key' => hash('sha256', $rule->id.'|'.$scopeKey.'|'.Str::uuid()),
                'client_id' => $account->client_id,
                'alert_rule_id' => $rule->id,
                'ad_account_id' => $account->id,
                'campaign_id' => $campaign?->id,
                'status' => 'open',
                'severity' => $rule->severity,
                'title' => $rule->name,
                'summary' => $result['reason'],
                'observed_value' => $result['observed'],
                'baseline_value' => $result['baseline'],
                'threshold_value' => $result['threshold'],
                'triggered_at' => now(),
                ]);
                AlertEvent::create(['alert_incident_id' => $incident->id, 'type' => 'triggered', 'metadata' => $result]);
            } else {
                $incident->update(['observed_value' => $result['observed'], 'baseline_value' => $result['baseline'], 'summary' => $result['reason']]);
            }
            return $incident;
        });

        if (! $incident->last_notified_at || $incident->last_notified_at->lte(now()->subHours(6))) {
            if (! $incident->snoozed_until || $incident->snoozed_until->isPast()) {
                $this->notifier->sendIncident($incident, 'triggered');
                $incident->update(['last_notified_at' => now()]);
            }
        }
        return $incident;
    }

    public function recover(AlertRule $rule, AdAccount $account, ?Campaign $campaign): void
    {
        AlertIncident::query()
            ->where('alert_rule_id', $rule->id)->where('ad_account_id', $account->id)
            ->when($campaign, fn ($q) => $q->where('campaign_id', $campaign->id), fn ($q) => $q->whereNull('campaign_id'))
            ->whereIn('status', ['open', 'acknowledged', 'snoozed'])->get()
            ->each(function (AlertIncident $incident) {
                $incident->update(['status' => 'resolved', 'resolved_at' => now()]);
                AlertEvent::create(['alert_incident_id' => $incident->id, 'type' => 'recovered']);
                $this->notifier->sendIncident($incident, 'recovered');
            });
    }

    public function transition(AlertIncident $incident, string $action, User $actor, array $data): AlertIncident
    {
        $updates = match ($action) {
            'acknowledge' => ['status' => 'acknowledged', 'acknowledged_at' => now()],
            'snooze' => ['status' => 'snoozed', 'snoozed_until' => $data['snoozed_until']],
            'resolve' => ['status' => 'resolved', 'resolved_at' => now()],
            'assign' => ['assigned_to' => $data['assigned_to']],
            'note' => [],
            default => abort(422, 'Unsupported alert action.'),
        };
        return DB::transaction(function () use ($incident, $action, $actor, $data, $updates) {
            if ($updates !== []) $incident->update($updates);
            AlertEvent::create(['alert_incident_id' => $incident->id, 'actor_id' => $actor->id, 'type' => $action, 'note' => $data['note'] ?? null, 'metadata' => $data]);
            return $incident->fresh(['events.actor', 'assignee']);
        });
    }
}
