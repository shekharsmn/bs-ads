<?php

namespace App\Services;

use App\Models\AlertIncident;
use App\Models\Client;
use App\Models\ReportRun;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleChatNotifier
{
    public function sendIncident(AlertIncident $incident, string $event): void
    {
        $incident->loadMissing(['client', 'adAccount', 'campaign', 'adGroup', 'ad', 'assignee']);
        $client = $incident->client;
        $webhook = $client->google_chat_webhook;
        if (! $webhook) {
            return;
        }

        $internal = $client->chat_visibility === 'internal';
        $status = $event === 'recovered' ? 'Recovered' : 'Alert triggered';
        $details = $internal
            ? $this->internalSummary($incident)
            : $this->clientSafeSummary($incident, $event);

        $this->post($webhook, $incident->id, [
            'cardsV2' => [[
                'cardId' => 'incident-'.$incident->id,
                'card' => [
                    'header' => ['title' => $status.': '.$incident->title, 'subtitle' => $client->name],
                    'sections' => [['widgets' => [
                        ['decoratedText' => ['topLabel' => strtoupper($incident->severity), 'text' => $details ?: 'Performance requires attention.']],
                        ['buttonList' => ['buttons' => $this->incidentButtons($incident)]],
                    ]]],
                ],
            ]],
        ]);
    }

    public function test(Client $client): void
    {
        abort_unless($client->google_chat_webhook, 422, 'No Google Chat webhook is configured.');
        $this->post($client->google_chat_webhook, 'test-'.$client->id, ['text' => "SharkAds reporting is connected for {$client->name}."]);
    }

    public function sendReport(ReportRun $run): bool
    {
        $run->loadMissing('client');
        if (! $run->client->google_chat_webhook) {
            return false;
        }
        $url = rtrim(config('app.frontend_url', config('app.url')), '/').'/shared/reports/'.$run->public_token;
        $this->post($run->client->google_chat_webhook, 'report-'.$run->id, ['text' => ucfirst($run->frequency)." performance report for {$run->client->name}: {$url}"]);

        return true;
    }

    private function post(string $webhook, string $threadKey, array $payload): void
    {
        $separator = str_contains($webhook, '?') ? '&' : '?';
        $response = Http::retry(3, 500)->timeout(10)->post($webhook.$separator.http_build_query(['threadKey' => $threadKey, 'messageReplyOption' => 'REPLY_MESSAGE_FALLBACK_TO_NEW_THREAD']), $payload);
        if (! $response->successful()) {
            Log::warning('Google Chat notification failed', ['status' => $response->status()]);
            throw new \RuntimeException('Google Chat notification failed with HTTP '.$response->status());
        }
    }

    private function clientSafeSummary(AlertIncident $incident, string $event): string
    {
        return $event === 'recovered'
            ? 'Performance has returned to the expected range. Monitoring continues.'
            : 'A performance signal moved outside the agreed range. Your SharkAds team is reviewing it.';
    }

    private function internalSummary(AlertIncident $incident): string
    {
        $lines = [$incident->summary ?: 'Performance requires attention.'];
        if ($incident->observed_spend !== null) {
            $lines[] = 'Observed spend: '.($incident->currency ?: '').' '.number_format((float) $incident->observed_spend, 2);
        }
        if ($incident->baseline_spend !== null) {
            $lines[] = 'Baseline spend: '.($incident->currency ?: '').' '.number_format((float) $incident->baseline_spend, 2);
        }
        $timezone = $incident->adAccount?->timezone ?: config('app.timezone');
        if ($incident->observed_period_start && $incident->observed_period_end) {
            $lines[] = 'Observed: '.$incident->observed_period_start->timezone($timezone)->format('d M Y, H:i').' – '.$incident->observed_period_end->timezone($timezone)->format('d M Y, H:i T');
        }
        if ($incident->baseline_period_start && $incident->baseline_period_end) {
            $lines[] = 'Baseline: '.$incident->baseline_period_start->timezone($timezone)->format('d M Y, H:i').' – '.$incident->baseline_period_end->timezone($timezone)->format('d M Y, H:i T');
        }
        if ($incident->assignee) {
            $lines[] = 'Owner: '.$incident->assignee->name;
        }

        return implode("\n", $lines);
    }

    private function incidentButtons(AlertIncident $incident): array
    {
        $base = rtrim(config('app.frontend_url', config('app.url')), '/');
        $clientId = $incident->client_id;
        $accountId = $incident->ad_account_id;
        $campaignId = $incident->campaign_id;
        $groupId = $incident->ad_group_id;
        $buttons = [
            ['text' => 'Open alert', 'url' => $base.'/alerts/'.$incident->id.'?client_id='.$clientId],
            ['text' => 'Client', 'url' => $base.'/clients/'.$clientId],
        ];
        if ($accountId) {
            $path = "/command-center/clients/{$clientId}/ad-accounts/{$accountId}";
            $buttons[] = ['text' => 'Ad account', 'url' => $base.$path];
            if ($campaignId) {
                $path .= "/campaigns/{$campaignId}";
                $buttons[] = ['text' => 'Campaign', 'url' => $base.$path];
                if ($groupId) {
                    $path .= "/ad-groups/{$groupId}";
                    $buttons[] = ['text' => $incident->adAccount?->platform === 'meta' ? 'Ad set' : 'Ad group', 'url' => $base.$path];
                    if ($incident->ad_id) {
                        $buttons[] = ['text' => 'Ad', 'url' => $base.$path.'/ads/'.$incident->ad_id];
                    }
                }
            }
        }

        return array_map(fn (array $button) => [
            'text' => $button['text'],
            'onClick' => ['openLink' => ['url' => $button['url']]],
        ], $buttons);
    }
}
