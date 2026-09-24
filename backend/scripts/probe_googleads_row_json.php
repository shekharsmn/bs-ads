<?php

require __DIR__.'/../vendor/autoload.php';

$row = new Google\Ads\GoogleAds\V23\Services\GoogleAdsRow();
$c = new Google\Ads\GoogleAds\V23\Resources\Campaign();
$c->setId(12345678901);
$c->setName('Test Campaign');
$c->setStatus(\Google\Ads\GoogleAds\V23\Enums\CampaignStatusEnum\CampaignStatus::ENABLED);
$row->setCampaign($c);

$m = new Google\Ads\GoogleAds\V23\Common\Metrics();
$m->setCostMicros(5000000);
$row->setMetrics($m);

echo $row->serializeToJsonString(), "\n";
