<?php

namespace App\Jobs\Concerns;

use App\Models\Report;

trait AbortIfReportCancelled
{
    /**
     * If the user cancelled the audit, mark terminal state and stop work.
     */
    protected function reportAbortIfCancelled(Report $report): bool
    {
        $report->refresh();
        if ($report->status === 'cancelled') {
            return true;
        }
        if ($report->cancel_requested_at !== null) {
            $report->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'completed_at' => now(),
                'error_message' => 'Cancelled by user.',
            ]);

            return true;
        }

        return false;
    }
}
