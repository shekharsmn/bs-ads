<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdGroup;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\ClientPortalAccountScope;
use App\Models\ReportTemplate;
use App\Services\AuditTrail;
use App\Services\ClientPortalAccess;
use App\Services\PerformanceReadService;
use App\Support\XlsxWriter;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ClientPortalPerformanceController extends Controller
{
    public function __construct(
        private readonly ClientPortalAccess $access,
        private readonly PerformanceReadService $performance,
    ) {}

    public function clients(Request $request)
    {
        return $this->access->clientsFor($request->user())->map(function (Client $client) {
            $visibleAccountCount = $client->portalAccountScopes()->whereNot('visibility_mode', ClientPortalAccountScope::HIDDEN)->count();
            return $client->only(['id', 'name', 'slug', 'timezone', 'currency', 'objective']) + ['visible_account_count' => $visibleAccountCount];
        })->values();
    }

    public function clientOverview(Request $request, Client $client)
    {
        $this->access->authorizeClient($request->user(), $client);
        [$start, $end] = $this->dates($request);
        $scopes = $client->portalAccountScopes()->with('campaigns:id')->whereNot('visibility_mode', ClientPortalAccountScope::HIDDEN)->get();

        return $this->performance->portalClientOverview($client, $scopes, $start, $end);
    }

    public function account(Request $request, AdAccount $account)
    {
        $scope = $this->access->scopeFor($request->user(), $account);
        return $this->performance->account($account) + ['visibility_mode' => $scope->visibility_mode];
    }

    public function overview(Request $request, AdAccount $account)
    {
        $scope = $this->access->scopeFor($request->user(), $account);
        [$start, $end] = $this->dates($request);
        return $this->performance->portalOverview($account->load('client:id,name,objective'), $scope, $start, $end);
    }

    public function campaigns(Request $request, AdAccount $account)
    {
        $scope = $this->access->scopeFor($request->user(), $account);
        [$start, $end] = $this->dates($request);
        return $this->performance->campaigns($account, $start, $end, $scope->campaignIds()) + ['visibility_mode' => $scope->visibility_mode];
    }

    public function adGroups(Request $request, AdAccount $account, Campaign $campaign)
    {
        $scope = $this->access->scopeFor($request->user(), $account);
        $this->access->authorizeCampaign($scope, $campaign);
        [$start, $end] = $this->dates($request);
        return $this->performance->adGroups($account, $campaign, $start, $end);
    }

    public function ads(Request $request, AdAccount $account, Campaign $campaign, AdGroup $adGroup)
    {
        $scope = $this->access->scopeFor($request->user(), $account);
        $this->access->authorizeCampaign($scope, $campaign);
        abort_unless((int) $adGroup->campaign_id === (int) $campaign->id, 404);
        [$start, $end] = $this->dates($request);
        return $this->performance->ads($account, $campaign, $adGroup, $start, $end);
    }

    public function ad(Request $request, AdAccount $account, Campaign $campaign, AdGroup $adGroup, Ad $ad)
    {
        $scope = $this->access->scopeFor($request->user(), $account);
        $this->access->authorizeCampaign($scope, $campaign);
        abort_unless((int) $adGroup->campaign_id === (int) $campaign->id && (int) $ad->ad_group_id === (int) $adGroup->id, 404);
        [$start, $end] = $this->dates($request);
        return $this->performance->ad($account, $campaign, $adGroup, $ad, $start, $end);
    }

    public function exportClient(Request $request, Client $client, string $format, AuditTrail $audit)
    {
        $payload = $this->clientOverview($request, $client);
        $rows = collect($payload['accounts'])->map(fn ($row) => $this->exportRow($row['account']['name'], $row['metrics'], $row['account']['currency']))->all();
        return $this->export($request, $format, $client, 'Account performance', [], [], $rows, $audit, null, $payload['currency_groups']);
    }

    public function exportAccount(Request $request, AdAccount $account, string $format, AuditTrail $audit)
    {
        $overview = $this->overview($request, $account);
        $campaigns = $this->campaigns($request, $account);
        $rows = collect($campaigns['campaigns'])->map(fn ($row) => $this->exportRow($row['campaign']->name, $row['metrics'], $account->currency))->all();
        return $this->export($request, $format, $account->client, $account->name, $overview['metrics'], $overview['series'], $rows, $audit, $overview['visibility_mode']);
    }

    public function exportCampaign(Request $request, AdAccount $account, Campaign $campaign, string $format, AuditTrail $audit)
    {
        $payload = $this->adGroups($request, $account, $campaign);
        $rows = collect($payload['ad_groups'])->map(fn ($row) => $this->exportRow($row['ad_group']->name, $row['metrics'], $account->currency))->all();
        return $this->export($request, $format, $account->client, $campaign->name, $payload['metrics'], $payload['series'], $rows, $audit);
    }

    public function exportAdGroup(Request $request, AdAccount $account, Campaign $campaign, AdGroup $adGroup, string $format, AuditTrail $audit)
    {
        $payload = $this->ads($request, $account, $campaign, $adGroup);
        $rows = collect($payload['ads'])->map(fn ($row) => $this->exportRow($row['ad']->name, $row['metrics'], $account->currency))->all();
        return $this->export($request, $format, $account->client, $adGroup->name, $payload['metrics'], $payload['series'], $rows, $audit);
    }

    public function exportAd(Request $request, AdAccount $account, Campaign $campaign, AdGroup $adGroup, Ad $ad, string $format, AuditTrail $audit)
    {
        $payload = $this->ad($request, $account, $campaign, $adGroup, $ad);
        return $this->export($request, $format, $account->client, $ad->name, $payload['metrics'], $payload['series'], [$this->exportRow($ad->name, $payload['metrics'], $account->currency)], $audit);
    }

    private function export(Request $request, string $format, Client $client, string $title, array $metrics, $series, array $rows, AuditTrail $audit, ?string $visibilityMode = null, $currencyGroups = [])
    {
        abort_unless(in_array($format, ['pdf', 'xlsx', 'csv'], true), 404);
        [$start, $end] = $this->dates($request);
        $template = ReportTemplate::query()->where(fn ($query) => $query->where('client_id', $client->id)->orWhereNull('client_id'))
            ->orderByRaw('client_id IS NULL')->orderByDesc('is_default')->first();
        $filename = Str::slug($client->name.'-'.$title.'-'.$start.'-'.$end);
        $audit->record('access.client_portal_exported', $client, [], ['format' => $format, 'title' => $title, 'period_start' => $start, 'period_end' => $end], $client->id, $request->user(), $request);

        if ($format === 'xlsx') {
            $path = XlsxWriter::createWorkbook([$title => [
                ['Name', 'Spend', 'Impressions', 'Clicks', 'Conversions', 'Leads', 'Purchases', 'Revenue', 'ROAS', 'CTR', 'CPC', 'CPL', 'Currency'],
                ...array_map(fn ($row) => array_values($row), $rows),
            ]]);
            return response()->download($path, $filename.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
        }

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($client, $title, $start, $end, $visibilityMode, $rows) {
                $output = fopen('php://output', 'wb');
                fwrite($output, "\xEF\xBB\xBF");
                fputcsv($output, ['Report', $this->csvValue($title)]);
                fputcsv($output, ['Client', $this->csvValue($client->name)]);
                fputcsv($output, ['Period', $start.' to '.$end]);
                fputcsv($output, ['Scope', $visibilityMode === ClientPortalAccountScope::SELECTED_CAMPAIGNS ? 'Selected campaigns' : 'All visible data']);
                fputcsv($output, []);
                fputcsv($output, ['Name', 'Spend', 'Impressions', 'Clicks', 'Conversions', 'Leads', 'Purchases', 'Revenue', 'ROAS', 'CTR', 'CPC', 'CPL', 'Currency']);
                foreach ($rows as $row) {
                    $values = array_values($row);
                    $values[0] = $this->csvValue((string) $values[0]);
                    fputcsv($output, $values);
                }
                fclose($output);
            }, $filename.'.csv', [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Cache-Control' => 'private, no-store, max-age=0',
            ]);
        }

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('client-portal-report', [
            'client' => $client, 'title' => $title, 'start' => $start, 'end' => $end,
            'metrics' => $metrics, 'series' => collect($series)->values(), 'rows' => $rows,
            'currencyGroups' => collect($currencyGroups)->values(),
            'brandName' => $template?->brand_name ?: 'SharkAds', 'brandColor' => $template?->brand_color ?: '#2563eb',
            'logoData' => $this->safeLogoData($template?->logo_url),
            'visibilityMode' => $visibilityMode, 'generatedAt' => now($client->timezone),
        ])->render());
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.pdf"',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function exportRow(string $name, array $metrics, string $currency): array
    {
        return [
            'name' => $name, 'spend' => $metrics['spend'] ?? 0, 'impressions' => $metrics['impressions'] ?? 0,
            'clicks' => $metrics['clicks'] ?? 0, 'conversions' => $metrics['conversions'] ?? 0, 'leads' => $metrics['leads'] ?? 0,
            'purchases' => $metrics['purchases'] ?? 0, 'revenue' => $metrics['conversion_value'] ?? 0, 'roas' => $metrics['roas'] ?? 0,
            'ctr' => $metrics['ctr'] ?? 0, 'cpc' => $metrics['cpc'] ?? 0, 'cpl' => $metrics['cpl'] ?? 0, 'currency' => $currency,
        ];
    }

    private function csvValue(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) ? "'".$value : $value;
    }

    private function dates(Request $request): array
    {
        $data = $request->validate(['start_date' => 'required|date', 'end_date' => 'required|date|after_or_equal:start_date']);
        return [$data['start_date'], $data['end_date']];
    }

    private function safeLogoData(?string $url): ?string
    {
        if (! $url) return null;
        $frontendHost = parse_url((string) config('app.frontend_url'), PHP_URL_HOST);
        $urlHost = parse_url($url, PHP_URL_HOST);
        if (! $frontendHost || ! hash_equals(strtolower($frontendHost), strtolower((string) $urlHost))) return null;
        $relativePath = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        $publicRoot = realpath(public_path());
        $path = realpath(public_path($relativePath));
        if (! $publicRoot || ! $path || ! str_starts_with(strtolower($path), strtolower($publicRoot.DIRECTORY_SEPARATOR)) || ! is_file($path)) return null;
        $mime = mime_content_type($path);
        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/svg+xml'], true) || filesize($path) > 2_000_000) return null;

        return 'data:'.$mime.';base64,'.base64_encode(file_get_contents($path));
    }
}
