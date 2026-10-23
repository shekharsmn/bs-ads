<?php

namespace App\Services\AdLibrary;

use App\Models\AdLibraryRun;

interface AdLibraryProvider
{
    public function scrape(AdLibraryRun $run): array;
}
