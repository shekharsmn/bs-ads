<?php

namespace App\Services\AdLibraryAudit;

use InvalidArgumentException;

class SafePublicUrl
{
    public function assert(string $url, ?string $requiredHost = null): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Only public HTTP and HTTPS URLs are allowed.');
        }
        if ($requiredHost !== null && $host !== strtolower(rtrim($requiredHost, '.'))) {
            throw new InvalidArgumentException('The URL must remain on the audit website host.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            throw new InvalidArgumentException('The website host could not be resolved.');
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new InvalidArgumentException('Private and reserved website hosts are not allowed.');
            }
        }

        return $url;
    }
}
