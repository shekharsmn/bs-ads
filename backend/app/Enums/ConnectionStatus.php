<?php

namespace App\Enums;

enum ConnectionStatus: string
{
    case Pending = 'pending';
    case Healthy = 'healthy';
    case Error = 'error';
    case Disconnected = 'disconnected';
}
