<?php

namespace App\Shopify\Console;

use App\Shopify\PrivacyLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class ReplayPrivacy extends Command
{
    protected $signature = 'shopify:replay-privacy';

    protected $description = 'Reapply retained Shopify redactions before making restored SQL data available';

    public function handle(PrivacyLedger $ledger): int
    {
        if (Schema::hasTable('shop_privacy')) {
            $this->info('Redactions replayed: '.$ledger->replay());
        }

        return self::SUCCESS;
    }
}
