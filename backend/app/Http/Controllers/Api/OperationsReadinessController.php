<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OperationsReadinessService;

class OperationsReadinessController extends Controller
{
    public function __invoke(OperationsReadinessService $readiness): array
    {
        return $readiness->inspect();
    }
}
