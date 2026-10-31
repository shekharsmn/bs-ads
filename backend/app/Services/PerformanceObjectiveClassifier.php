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

        $leads = (float) ($row->leads ?? 0);
        $purchases = (float) ($row->purchases ?? 0);
        if ($leads !== $purchases) {
            return $purchases > $leads ? 'sales' : 'leads';
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

        return $leadSpend === $salesSpend ? $this->forClientObjective($clientObjective) : ($salesSpend > $leadSpend ? 'sales' : 'leads');
    }

    public function forClientObjective(string $objective): string
    {
        return in_array(strtolower($objective), ['ecommerce', 'sales', 'purchase'], true) ? 'sales' : 'leads';
    }
}
