<?php

namespace App\Services;

use App\Models\PlatformConnection;
use Google\Ads\GoogleAds\V23\Services\ListAccessibleCustomersRequest;
use Google\Ads\GoogleAds\V23\Services\SearchGoogleAdsStreamRequest;

class GoogleAdsAccountDiscovery
{
    /** @var list<string> */
    private array $warnings = [];

    /**
     * @return list<array<string, mixed>>
     */
    public function discover(PlatformConnection $connection): array
    {
        $this->warnings = [];
        $refreshToken = $connection->credentials['refresh_token'] ?? null;
        if (! $refreshToken) {
            throw new \RuntimeException('Google Ads connection has no refresh token. Reconnect it with Google.');
        }

        $client = GoogleAdsClientFactory::fromRefreshToken($refreshToken);
        $response = $client->getCustomerServiceClient()->listAccessibleCustomers(new ListAccessibleCustomersRequest);
        $rootIds = array_values(array_unique(array_map(
            static fn (string $name): string => basename($name),
            iterator_to_array($response->getResourceNames())
        )));

        $accounts = [];
        foreach ($rootIds as $rootId) {
            try {
                $rootClient = GoogleAdsClientFactory::fromRefreshToken($refreshToken, $rootId);
                $root = $this->customer($rootClient, $rootId);
                $this->keepPreferred($accounts, $root + [
                    'level' => 0,
                    'manager_external_id' => null,
                ]);

                if (! $root['is_manager']) {
                    continue;
                }

                foreach ($this->hierarchy($rootClient, $rootId) as $account) {
                    $this->keepPreferred($accounts, $account + [
                        'manager_external_id' => $account['external_id'] === $rootId ? null : $rootId,
                    ]);
                }
            } catch (\Throwable $error) {
                $this->warnings[] = $this->rootWarning($rootId, $error);
            }
        }

        if ($accounts === []) {
            $details = $this->warnings !== [] ? ' '.implode(' ', $this->warnings) : '';
            throw new \RuntimeException('Google Ads did not return any enabled accounts for this login.'.$details);
        }

        $accounts = array_values($accounts);
        usort($accounts, static function (array $a, array $b): int {
            $aGroup = $a['manager_external_id'] ?? ($a['is_manager'] ? $a['external_id'] : 'direct-'.$a['external_id']);
            $bGroup = $b['manager_external_id'] ?? ($b['is_manager'] ? $b['external_id'] : 'direct-'.$b['external_id']);

            return [$aGroup, $a['level'], ! $a['is_manager'], $a['name']] <=> [$bGroup, $b['level'], ! $b['is_manager'], $b['name']];
        });

        return $accounts;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return array<string, mixed> */
    private function customer($client, string $customerId): array
    {
        $query = 'SELECT customer.id, customer.descriptive_name, customer.currency_code, customer.time_zone, customer.manager FROM customer LIMIT 1';
        foreach ($this->rows($client, $customerId, $query) as $row) {
            $customer = $row->getCustomer();

            return [
                'external_id' => (string) $customer->getId(),
                'name' => $customer->getDescriptiveName() ?: $customerId,
                'currency' => $customer->getCurrencyCode() ?: null,
                'timezone' => $customer->getTimeZone() ?: null,
                'is_manager' => (bool) $customer->getManager(),
                'selectable' => ! $customer->getManager(),
            ];
        }

        throw new \RuntimeException("Google Ads customer {$customerId} returned no account details.");
    }

    /** @return list<array<string, mixed>> */
    private function hierarchy($client, string $managerId): array
    {
        $query = "SELECT customer_client.id, customer_client.descriptive_name, customer_client.currency_code, customer_client.time_zone, customer_client.manager, customer_client.level FROM customer_client WHERE customer_client.status = 'ENABLED'";
        $accounts = [];
        foreach ($this->rows($client, $managerId, $query) as $row) {
            $customer = $row->getCustomerClient();
            $accounts[] = [
                'external_id' => (string) $customer->getId(),
                'name' => $customer->getDescriptiveName() ?: (string) $customer->getId(),
                'currency' => $customer->getCurrencyCode() ?: null,
                'timezone' => $customer->getTimeZone() ?: null,
                'is_manager' => (bool) $customer->getManager(),
                'selectable' => ! $customer->getManager(),
                'level' => (int) $customer->getLevel(),
            ];
        }

        return $accounts;
    }

    private function rows($client, string $customerId, string $query): \Generator
    {
        $stream = $client->getGoogleAdsServiceClient()->searchStream(
            SearchGoogleAdsStreamRequest::build($customerId, $query)
        );
        foreach ($stream->readAll() as $chunk) {
            foreach ($chunk->getResults() as $row) {
                yield $row;
            }
        }
    }

    /** @param array<string, array<string, mixed>> $accounts */
    private function keepPreferred(array &$accounts, array $candidate): void
    {
        $id = $candidate['external_id'];
        $current = $accounts[$id] ?? null;
        if (! $current || (($current['manager_external_id'] ?? null) !== null && ($candidate['manager_external_id'] ?? null) === null)) {
            $accounts[$id] = $candidate;
        }
    }

    private function rootWarning(string $customerId, \Throwable $error): string
    {
        $message = $error->getMessage();
        if (str_contains($message, 'CUSTOMER_NOT_ENABLED')) {
            return "Customer {$customerId} is not enabled or has been deactivated in Google Ads, so it was skipped.";
        }
        if (str_contains($message, 'USER_PERMISSION_DENIED')) {
            return "Customer {$customerId} could not be opened through the selected Google Ads login. Check the manager-account access path.";
        }

        return "Customer {$customerId} could not be inspected: ".mb_substr($message, 0, 500);
    }
}
