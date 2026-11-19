<?php

namespace App\Enums;

enum ReportingObjective: string
{
    case LeadGeneration = 'lead_gen';
    case Ecommerce = 'ecommerce';
    case Both = 'both';
    case AppInstalls = 'app_installs';
}
