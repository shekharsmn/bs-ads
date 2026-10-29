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
        $incident->loadMissing(['client', 'adAccount', 'campaign', 'assignee']);
        $client = $incident->client;
        $webhook = $client->google_chat_webhook;
        if (! $webhook) {
            return;
        }

        $internal = $client->chat_visibility === 'internal';
        $status = $event === 'recovered' ? 'Recovered' : 'Alert triggered';
        $details = $internal
            ? trim(($incident->summary ?? '').($incident->assignee ? "\nOwner: {$incident->assignee->name}" : ''))
            : $this->clientSafeSummary($incident, $event);

        $this->post($webhook, $incident->id, [
            'cardsV2' => [[
                'cardId' => 'incident-'.$incident->id,
                'card' => [
                    'header' => ['title' => $status.': '.$incident->title, 'subtitle' => $client->name],
                    'sections' => [['widgets' => [
                        ['decoratedText' => ['topLabel' => strtoupper($incident->severity), 'text' => $details ?: 'Performance requires attention.']],
                        ['buttonList' => ['buttons' => [[
                            'text' => 'Open incident',
                            'onClick' => ['openLink' => ['url' => rtrim(config('app.frontend_url', config('app.url')), '/').'/alerts/'.$incident->id]],
                        ]]]],
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
}
