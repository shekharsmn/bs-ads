<?php

namespace App\Enums;

enum IncidentStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Snoozed = 'snoozed';
    case Resolved = 'resolved';
}
