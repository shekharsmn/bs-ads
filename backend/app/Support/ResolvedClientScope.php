<?php

namespace App\Support;

use App\Models\AdAccount;
use App\Models\Campaign;
use App\Models\Client;

final readonly class ResolvedClientScope
{
    public function __construct(
        public ?Client $client,
        public ?AdAccount $adAccount = null,
        public ?Campaign $campaign = null,
    ) {}
}
