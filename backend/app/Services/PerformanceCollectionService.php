<?php

namespace App\Services;

use App\Enums\Platform;
use App\Models\AdAccount;
use App\Models\Campaign;
use App\Models\ConversionMapping;
use App\Models\MetricSnapshot;
use Google\Ads\GoogleAds\V23\Services\SearchGoogleAdsStreamRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class PerformanceCollectionService
{
    public function collect(AdAccount $account): void
    {
        $account->loadMissing(['connection', 'client']);
        $rows = $account->platform === Platform::Google ? $this->google($account) : $this->meta($account);
        DB::transaction(function () use ($account, $rows) {
            $accountByDate = [];
            foreach ($rows as $row) {
                $campaign = Campaign::updateOrCreate(
                    ['ad_account_id' => $account->id, 'external_id' => (string) $row['campaign_external_id']],
                    ['name' => $row['campaign_name'], 'status' => $row['campaign_status'], 'type' => $row['campaign_type'], 'daily_budget' => $row['daily_budget']]
                );
                $payload = array_merge($row['metrics'], [
                    'ad_account_id' => $account->id,
                    'campaign_id' => $campaign->id,
                    'scope' => 'campaign',
                    'scope_key' => 'campaign:'.$campaign->id,
                    'metric_date' => $row['date'],
                    'currency' => $account->currency,
                    'mapping_version' => $row['mapping_version'],
                    'collected_at' => now(),
                ]);
                MetricSnapshot::updateOrCreate(
                    ['ad_account_id' => $account->id, 'scope_key' => $payload['scope_key'], 'metric_date' => $row['date']],
                    $payload
                );
                foreach ($row['metrics'] as $key => $value) $accountByDate[$row['date']][$key] = ($accountByDate[$row['date']][$key] ?? 0) + $value;
                $accountByDate[$row['date']]['mapping_version'] = max($accountByDate[$row['date']]['mapping_version'] ?? 1, $row['mapping_version']);
            }
            foreach ($accountByDate as $date => $metrics) {
                $mappingVersion = $metrics['mapping_version'];
                unset($metrics['mapping_version']);
                MetricSnapshot::updateOrCreate(
                    ['ad_account_id' => $account->id, 'scope_key' => 'account:'.$account->id, 'metric_date' => $date],
                    array_merge($metrics, ['campaign_id' => null, 'scope' => 'account', 'currency' => $account->currency, 'mapping_version' => $mappingVersion, 'collected_at' => now()])
                );
            }
            $account->update(['last_collected_at' => now(), 'last_error' => null]);
            $account->connection->update(['status' => 'healthy', 'last_synced_at' => now(), 'last_healthy_at' => now(), 'last_error' => null]);
        });
    }

    private function google(AdAccount $account): array
    {
        $credentials = $account->connection->credentials;
        $client = GoogleAdsClientFactory::fromRefreshToken($credentials['refresh_token'], $account->manager_external_id ?? $credentials['login_customer_id'] ?? $account->connection->manager_external_id);
        $start = now($account->timezone)->subDays(14)->toDateString();
        $end = now($account->timezone)->toDateString();
        $query = "SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type, campaign_budget.amount_micros, segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value FROM campaign WHERE segments.date BETWEEN '$start' AND '$end' AND campaign.status != 'REMOVED'";
        $service = $client->getGoogleAdsServiceClient();
        $stream = $service->searchStream(SearchGoogleAdsStreamRequest::build($account->external_id, $query));
        $mappings = ConversionMapping::query()->where('client_id', $account->client_id)->where('platform', 'google')->where('active', true)->get()->keyBy('external_action');
        $mapped = [];
        if ($mappings->isNotEmpty()) {
            $conversionQuery = "SELECT campaign.id, segments.date, segments.conversion_action_name, metrics.conversions, metrics.conversions_value FROM campaign WHERE segments.date BETWEEN '$start' AND '$end' AND metrics.conversions > 0";
            foreach ($service->searchStream(SearchGoogleAdsStreamRequest::build($account->external_id, $conversionQuery))->readAll() as $conversionChunk) {
                foreach ($conversionChunk->getResults() as $conversionRow) {
                    $campaignSlice = json_decode($conversionRow->getCampaign()->serializeToJsonString(), true, 512, JSON_BIGINT_AS_STRING) ?: [];
                    $metricSlice = json_decode($conversionRow->getMetrics()->serializeToJsonString(), true) ?: [];
                    $segmentSlice = json_decode($conversionRow->getSegments()->serializeToJsonString(), true) ?: [];
                    $mapping = $mappings->get($segmentSlice['conversionActionName'] ?? '');
                    if (! $mapping) continue;
                    $key = ($campaignSlice['id'] ?? '').'|'.($segmentSlice['date'] ?? '');
                    $mapped[$key] ??= ['conversions' => 0.0, 'leads' => 0.0, 'purchases' => 0.0, 'conversion_value' => 0.0];
                    $quantity = ((float) ($metricSlice['conversions'] ?? 0)) * (float) $mapping->weight;
                    $target = $mapping->canonical_metric->value;
                    if ($target !== 'conversion_value') $mapped[$key][$target] += $quantity;
                    if (in_array($target, ['conversions', 'leads', 'purchases'], true)) $mapped[$key]['conversions'] += $target === 'conversions' ? 0 : $quantity;
                    $mapped[$key]['conversion_value'] += ((float) ($metricSlice['conversionsValue'] ?? 0)) * (float) $mapping->weight;
                }
            }
        }
        $rows = [];
        foreach ($stream->readAll() as $chunk) {
            foreach ($chunk->getResults() as $row) {
                $campaign = json_decode($row->getCampaign()->serializeToJsonString(), true, 512, JSON_BIGINT_AS_STRING) ?: [];
                $metrics = json_decode($row->getMetrics()->serializeToJsonString(), true) ?: [];
                $segments = json_decode($row->getSegments()->serializeToJsonString(), true) ?: [];
                $budget = json_decode($row->getCampaignBudget()->serializeToJsonString(), true) ?: [];
                $canonical = $mapped[(string) ($campaign['id'] ?? '').'|'.($segments['date'] ?? '')] ?? [
                    'conversions' => (float) ($metrics['conversions'] ?? 0), 'leads' => 0, 'purchases' => 0,
                    'conversion_value' => (float) ($metrics['conversionsValue'] ?? 0),
                ];
                $rows[] = [
                    'campaign_external_id' => (string) ($campaign['id'] ?? ''),
                    'campaign_name' => (string) ($campaign['name'] ?? 'Untitled campaign'),
                    'campaign_status' => (string) ($campaign['status'] ?? 'UNKNOWN'),
                    'campaign_type' => (string) ($campaign['advertisingChannelType'] ?? null),
                    'daily_budget' => isset($budget['amountMicros']) ? ((float) $budget['amountMicros']) / 1_000_000 : null,
                    'date' => $segments['date'],
                    'mapping_version' => $this->mappingVersion($account),
                    'metrics' => [
                        'spend' => ((float) ($metrics['costMicros'] ?? 0)) / 1_000_000,
                        'impressions' => (int) ($metrics['impressions'] ?? 0), 'reach' => 0,
                        'clicks' => (int) ($metrics['clicks'] ?? 0),
                        ...$canonical,
                    ],
                ];
            }
        }
        return $rows;
    }

    private function meta(AdAccount $account): array
    {
        $credentials = $account->connection->credentials;
        $start = now($account->timezone)->subDays(14)->toDateString();
        $end = now($account->timezone)->toDateString();
        $url = 'https://graph.facebook.com/v24.0/'.(str_starts_with($account->external_id, 'act_') ? $account->external_id : 'act_'.$account->external_id).'/insights';
        $response = Http::timeout(60)->retry(3, 500)->get($url, [
            'access_token' => $credentials['access_token'], 'level' => 'campaign', 'time_increment' => 1,
            'time_range' => json_encode(['since' => $start, 'until' => $end]),
            'fields' => 'campaign_id,campaign_name,objective,spend,impressions,reach,clicks,actions,action_values,date_start,date_stop',
            'limit' => 500,
        ]);
        $response->throw();
        $data = $response->json('data', []);
        while ($next = data_get($response->json(), 'paging.next')) {
            $response = Http::timeout(60)->retry(3, 500)->get($next);
            $response->throw();
            $data = array_merge($data, $response->json('data', []));
        }
        $mappings = ConversionMapping::query()->where('client_id', $account->client_id)->where('platform', 'meta')->where('active', true)->get()->keyBy('external_action');
        $version = $this->mappingVersion($account);
        return array_map(function ($row) use ($mappings, $version) {
            $canonical = ['conversions' => 0.0, 'leads' => 0.0, 'purchases' => 0.0, 'conversion_value' => 0.0];
            foreach ($row['actions'] ?? [] as $action) {
                $mapping = $mappings->get($action['action_type'] ?? '');
                if ($mapping && $mapping->canonical_metric->value !== 'conversion_value') {
                    $quantity = ((float) ($action['value'] ?? 0)) * (float) $mapping->weight;
                    $canonical[$mapping->canonical_metric->value] += $quantity;
                    if (in_array($mapping->canonical_metric->value, ['leads', 'purchases'], true)) $canonical['conversions'] += $quantity;
                }
            }
            foreach ($row['action_values'] ?? [] as $action) {
                $mapping = $mappings->get($action['action_type'] ?? '');
                if ($mapping) $canonical['conversion_value'] += ((float) ($action['value'] ?? 0)) * (float) $mapping->weight;
            }
            if ($mappings->isEmpty()) {
                foreach ($row['actions'] ?? [] as $action) {
                    $type = (string) ($action['action_type'] ?? '');
                    if (str_contains($type, 'lead')) $canonical['leads'] += (float) ($action['value'] ?? 0);
                    if (str_contains($type, 'purchase')) $canonical['purchases'] += (float) ($action['value'] ?? 0);
                }
                $canonical['conversions'] = $canonical['leads'] + $canonical['purchases'];
            }
            return [
                'campaign_external_id' => (string) $row['campaign_id'], 'campaign_name' => $row['campaign_name'] ?? 'Untitled campaign',
                'campaign_status' => 'ACTIVE', 'campaign_type' => $row['objective'] ?? null, 'daily_budget' => null,
                'date' => $row['date_start'], 'mapping_version' => $version,
                'metrics' => array_merge(['spend' => (float) ($row['spend'] ?? 0), 'impressions' => (int) ($row['impressions'] ?? 0), 'reach' => (int) ($row['reach'] ?? 0), 'clicks' => (int) ($row['clicks'] ?? 0)], $canonical),
            ];
        }, $data);
    }

    private function mappingVersion(AdAccount $account): int
    {
        return (int) (ConversionMapping::where('client_id', $account->client_id)->where('platform', $account->platform->value)->max('version') ?? 1);
    }
}
