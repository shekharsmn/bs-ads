<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReportRun;

class PublicPerformanceReportController extends Controller
{
    public function show(string $token)
    {
        $run = ReportRun::where('public_token', $token)->where('status', 'complete')->firstOrFail();
        abort_if(! $run->expires_at || $run->expires_at->isPast(), 410, 'This report link has expired.');
        return response()->json(['frequency' => $run->frequency, 'period_start' => $run->period_start, 'period_end' => $run->period_end, 'generated_at' => $run->generated_at, 'expires_at' => $run->expires_at, 'snapshot' => $run->snapshot])->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
