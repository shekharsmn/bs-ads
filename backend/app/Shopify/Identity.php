<?php

namespace App\Shopify;

use App\Shopify\Models\AppRegistration;

final class Identity
{
    public static function domain(string $domain): string
    {
        $domain = strtolower($domain);
        if (! preg_match('/\A[a-z0-9][a-z0-9-]*\.myshopify\.com\z/D', $domain)) {
            throw new ShopifyException('invalid_shop');
        }

        return $domain;
    }

    public function verify(string $jwt, AppRegistration $app): array
    {
        if (strlen($jwt) > 16384) {
            throw new ShopifyException('invalid_identity');
        }
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new ShopifyException('invalid_identity');
        }
        $decode = static function ($value) {
            $raw = base64_decode(strtr($value, '-_', '+/'), true);

            return $raw === false ? null : json_decode($raw, true);
        };
        $header = $decode($parts[0]);
        $claims = $decode($parts[1]);
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $parts[0].'.'.$parts[1], $app->secret, true)), '+/', '-_'), '=');
        $valid = hash_equals($signature, $parts[2]);
        if (! $valid && $app->previous_secret) {
            $valid = hash_equals(rtrim(strtr(base64_encode(hash_hmac('sha256', $parts[0].'.'.$parts[1], $app->previous_secret, true)), '+/', '-_'), '='), $parts[2]);
        }
        if (($header['alg'] ?? null) !== 'HS256' || ! $valid || ! is_array($claims)) {
            throw new ShopifyException('invalid_identity');
        }
        $now = now()->timestamp;
        if (($claims['aud'] ?? null) !== $app->client_key || ! is_numeric($claims['exp'] ?? null) || $claims['exp'] <= $now ||
            ! is_numeric($claims['nbf'] ?? null) || $claims['nbf'] > $now + 5 || ! is_numeric($claims['iat'] ?? null) || $claims['iat'] > $now + 5 ||
            $claims['exp'] - $claims['iat'] > 300 || ! is_string($claims['sub'] ?? null)) {
            throw new ShopifyException('invalid_identity');
        }
        $dest = $claims['dest'] ?? '';
        $domain = self::domain((string) parse_url($dest, PHP_URL_HOST));
        if ($dest !== 'https://'.$domain || ($claims['iss'] ?? '') !== 'https://'.$domain.'/admin') {
            throw new ShopifyException('invalid_identity');
        }

        return ['domain' => $domain, 'staff_id' => $claims['sub']];
    }
}
