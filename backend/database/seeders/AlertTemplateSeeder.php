<?php

namespace Database\Seeders;

use App\Models\AlertRule;
use App\Models\AlertTemplate;
use Illuminate\Database\Seeder;

class AlertTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            ['key' => 'ctr-drop', 'name' => 'CTR dropped', 'description' => 'CTR is down versus the preceding seven-day baseline.', 'metric' => 'ctr', 'operator' => 'percentage_drop', 'threshold' => 25, 'severity' => 'warning', 'window_days' => 7, 'minimum_impressions' => 1000],
            ['key' => 'cpl-increase', 'name' => 'Cost per lead increased', 'description' => 'CPL is materially higher than its baseline.', 'metric' => 'cpl', 'operator' => 'percentage_increase', 'threshold' => 25, 'severity' => 'warning', 'window_days' => 7, 'minimum_spend' => 100],
            ['key' => 'no-conversions', 'name' => 'Spend without conversions', 'description' => 'Meaningful spend produced no mapped conversions.', 'metric' => 'conversions', 'operator' => 'no_conversions_with_spend', 'threshold' => null, 'severity' => 'critical', 'window_days' => 1, 'minimum_spend' => 100],
            ['key' => 'budget-over', 'name' => 'Budget over-pacing', 'description' => 'Month-to-date spend is above expected pacing.', 'metric' => 'spend', 'operator' => 'budget_over_pacing', 'threshold' => 115, 'severity' => 'warning', 'window_days' => 31],
            ['key' => 'data-stale', 'name' => 'Data is stale', 'description' => 'No fresh platform data has arrived.', 'metric' => 'spend', 'operator' => 'data_stale', 'threshold' => 3, 'severity' => 'critical', 'window_days' => 1],
            ['key' => 'connection-error', 'name' => 'Platform connection error', 'description' => 'Credentials or the platform API require attention.', 'metric' => 'spend', 'operator' => 'connection_error', 'threshold' => null, 'severity' => 'critical', 'window_days' => 1],
        ];
        foreach ($templates as $definition) {
            $ruleFields = collect($definition)->only(['name', 'metric', 'operator', 'threshold', 'severity', 'window_days', 'minimum_spend', 'minimum_impressions'])->all();
            $template = AlertTemplate::updateOrCreate(['key' => $definition['key']], collect($definition)->except(['window_days', 'minimum_spend', 'minimum_impressions'])->all());
            AlertRule::updateOrCreate(['alert_template_id' => $template->id, 'client_id' => null, 'ad_account_id' => null, 'campaign_id' => null], $ruleFields + ['enabled' => true]);
        }
    }
}
