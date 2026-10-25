<?php

namespace App\Console\Commands;

use App\Services\OperationsReadinessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckOperationsReadiness extends Command
{
    protected $signature = 'operations:readiness {--json}';

    protected $description = 'Inspect production configuration and operational heartbeats without blocking deployment';

    public function handle(OperationsReadinessService $readiness): int
    {
        $result = $readiness->inspect();
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($result['warnings'] as $warning) {
                $this->warn($warning);
            }
        }
        if ($result['warnings'] !== []) {
            Log::warning('Operations readiness warnings', ['warnings' => $result['warnings']]);
        }
        if ($result['warnings'] === []) {
            $this->info('No production readiness warnings detected.');
        }

        return self::SUCCESS;
    }
}
