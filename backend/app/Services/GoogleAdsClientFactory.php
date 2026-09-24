<?php

namespace App\Services;

use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\Lib\V23\GoogleAdsClientBuilder;

class GoogleAdsClientFactory
{
    public static function isConfigured(): bool
    {
        return (bool) config('google_ads.developer_token')
            && (bool) config('google_ads.client_id')
            && (bool) config('google_ads.client_secret');
    }

    /**
     * @param  string  $refreshToken  OAuth refresh token
     * @param  string|null  $loginCustomerId  Manager (MCC) ID without dashes, or null
     */
    public static function fromRefreshToken(string $refreshToken, ?string $loginCustomerId = null): \Google\Ads\GoogleAds\Lib\V23\GoogleAdsClient
    {
        $oauth = (new OAuth2TokenBuilder)
            ->withClientId(config('google_ads.client_id'))
            ->withClientSecret(config('google_ads.client_secret'))
            ->withRefreshToken($refreshToken)
            ->build();

        $builder = (new GoogleAdsClientBuilder)
            ->withDeveloperToken(config('google_ads.developer_token'))
            ->withOAuth2Credential($oauth);

        if ($loginCustomerId !== null && $loginCustomerId !== '') {
            $builder->withLoginCustomerId(preg_replace('/\D/', '', $loginCustomerId));
        }

        return $builder->build();
    }
}
