<?php

namespace App\Services;

class PerformanceObjectiveClassifier
{
    public function classify(object $row, string $clientObjective): string
    {
        $campaignType = strtolower((string) ($row->campaign_type ?? ''));
        if (str_contains($campaignType, 'lead')) {
            return 'leads';
        }
        if (str_contains($campaignType, 'sale') || str_contains($campaignType, 'purchase') || str_contains($campaignType, 'shop')) {
            return 'sales';
        }
        if (str_contains($campaignType, 'app') || str_contains($campaignType, 'install')) {
            return 'installs';
        }

        $leads = (float) ($row->leads ?? 0);
        $purchases = (float) ($row->purchases ?? 0);
        $installs = (float) ($row->installs ?? 0);
        if (count(array_unique([$leads, $purchases, $installs])) > 1) {
            return collect(['leads' => $leads, 'sales' => $purchases, 'installs' => $installs])->sortDesc()->keys()->first();
        }
        if (strtolower($clientObjective) === 'both' && (float) ($row->conversion_value ?? 0) > 0) {
            return 'sales';
        }

        return $this->forClientObjective($clientObjective);
    }

    public function winning($spendByType, string $clientObjective): string
    {
        $leadSpend = (float) ($spendByType['leads'] ?? 0);
        $salesSpend = (float) ($spendByType['sales'] ?? 0);
        $installSpend = (float) ($spendByType['installs'] ?? 0);

        $spend = collect(['leads' => $leadSpend, 'sales' => $salesSpend, 'installs' => $installSpend]);
        if ($spend->unique()->count() === 1) {
            return $this->forClientObjective($clientObjective);
        }

        return $spend->sortDesc()->keys()->first();
    }

    public function forClientObjective(string $objective): string
    {
        return match (strtolower($objective)) {
            'ecommerce', 'sales', 'purchase' => 'sales',
            'app_installs', 'installs' => 'installs',
            default => 'leads',
        };
    }
}
