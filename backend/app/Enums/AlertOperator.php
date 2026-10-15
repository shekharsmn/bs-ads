<?php

namespace App\Enums;

enum AlertOperator: string
{
    case Below = 'below';
    case Above = 'above';
    case PercentageDrop = 'percentage_drop';
    case PercentageIncrease = 'percentage_increase';
    case NoSpend = 'no_spend';
    case NoImpressions = 'no_impressions';
    case NoConversionsWithSpend = 'no_conversions_with_spend';
    case BudgetUnderPacing = 'budget_under_pacing';
    case BudgetOverPacing = 'budget_over_pacing';
    case DataStale = 'data_stale';
    case ConnectionError = 'connection_error';
}
