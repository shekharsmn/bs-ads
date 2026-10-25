<?php

namespace App\Console\Commands;

use App\Models\AiBudgetPolicy;
use App\Models\NotificationDestination;
use App\Services\NotificationDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MonitorAiBudgets extends Command
{
    protected $signature = 'ai:monitor-budgets';

    protected $description = 'Send warning-only AI budget threshold notifications';

    public function handle(NotificationDispatcher $notifications): int
    {
        $month = now()->startOfMonth();
        AiBudgetPolicy::where('enabled', true)->chunkById(100, function ($policies) use ($notifications, $month) {
            foreach ($policies as $policy) {
                $query = DB::table('gemini_request_logs')->leftJoin('reports', 'reports.id', '=', 'gemini_request_logs.report_id')->where('gemini_request_logs.created_at', '>=', $month);
                if ($policy->client_id) {
                    $query->where('reports.client_id', $policy->client_id);
                }
                $spent = (float) $query->sum('gemini_request_logs.estimated_cost');
                if (! $policy->client_id) {
                    $spent += (float) DB::table('ad_library_audit_ai_requests')->where('created_at', '>=', $month)->sum('estimated_cost');
                }
                $percent = $policy->monthly_budget > 0 ? $spent / (float) $policy->monthly_budget * 100 : 0;
                $level = $percent >= 100 ? 'exceeded' : ($percent >= $policy->critical_percent ? 'critical' : ($percent >= $policy->warning_percent ? 'warning' : null));
                if (! $level) {
                    continue;
                }
                $clientIds = $policy->client_id ? collect([$policy->client_id]) : NotificationDestination::where('enabled', true)->distinct()->pluck('client_id');
                foreach ($clientIds as $clientId) {
                    $notifications->dispatch((int) $clientId, 'ai_budget', ['title' => 'AI budget '.$level, 'text' => round($percent, 1).'% of the monthly AI budget has been used.', 'level' => $level, 'spent' => $spent, 'budget' => (float) $policy->monthly_budget], 'budget:'.$policy->id.':'.$month->format('Y-m').':'.$level);
                }
            }
        });

        return self::SUCCESS;
    }
}
