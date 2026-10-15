<?php

namespace App\Enums;

enum CanonicalMetric: string
{
    case Spend = 'spend';
    case Impressions = 'impressions';
    case Reach = 'reach';
    case Clicks = 'clicks';
    case Conversions = 'conversions';
    case Leads = 'leads';
    case Purchases = 'purchases';
    case ConversionValue = 'conversion_value';
    case Ctr = 'ctr';
    case Cpc = 'cpc';
    case Cpm = 'cpm';
    case Cpa = 'cpa';
    case Cpl = 'cpl';
    case ConversionRate = 'conversion_rate';
    case Roas = 'roas';
    case Frequency = 'frequency';
}
