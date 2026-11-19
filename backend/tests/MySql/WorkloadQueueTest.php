<?php

namespace Tests\MySql;

use App\Models\AdAccount;
use App\Models\Client;
use App\Models\PlatformConnection;
use App\Services\WorkloadQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WorkloadQueueTest extends TestCase
{
    public function test_concurrent_admissions_and_abandoned_reservations_keep_one_incremental_job(): void
    {
        $database = DB::connection()->getConfig();
        if (! app()->environment('testing') || $database['driver'] !== 'mysql' ||
            $database['host'] !== '127.0.0.1' || (int) $database['port'] !== 43316 ||
            $database['database'] !== 'bs_ads_shopify_test' || $database['username'] !== 'bs_ads_test' ||
            ! empty($database['unix_socket'])) {
            throw new \RuntimeException('Refusing queue test on a non-disposable database.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
        config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 1200, 'cache.default' => 'database']);
        $client = Client::create(['name' => 'Queue fixture', 'slug' => 'queue-fixture', 'currency' => 'INR']);
        $connection = PlatformConnection::create(['platform' => 'google', 'name' => 'Fixture', 'scope' => 'agency', 'credentials' => [], 'status' => 'healthy']);
        $account = AdAccount::create([
            'client_id' => $client->id, 'platform_connection_id' => $connection->id, 'platform' => 'google',
            'external_id' => 'fixture', 'name' => 'Fixture', 'currency' => 'INR', 'timezone' => 'Asia/Kolkata', 'status' => 'active',
        ]);
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo (int) app(App\Services\WorkloadQueue::class)->collection((int) $argv[1], refresh: $argv[2]);
PHP;
        $environment = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '43316',
            'DB_DATABASE' => 'bs_ads_shopify_test', 'DB_USERNAME' => 'bs_ads_test', 'DB_PASSWORD' => $database['password'],
            'DB_URL' => false, 'DB_SOCKET' => '', 'CACHE_STORE' => 'database', 'QUEUE_CONNECTION' => 'database',
        ];
        $first = new Process([PHP_BINARY, '-r', $code, (string) $account->id, 'first'], base_path(), $environment, timeout: 15);
        $second = new Process([PHP_BINARY, '-r', $code, (string) $account->id, 'second'], base_path(), $environment, timeout: 15);
        $first->start();
        $second->start();
        foreach ([$first, $second] as $process) {
            $this->assertSame(0, $process->wait(), $process->getErrorOutput());
        }
        $this->assertEqualsCanonicalizing(['0', '1'], [trim($first->getOutput()), trim($second->getOutput())]);
        $queue = Queue::connection('database');
        $this->assertSame(1, $queue->size('collection'));
        $original = $queue->pop('collection');
        $this->travel(21)->minutes();
        $this->assertFalse(app(WorkloadQueue::class)->collection($account->id));
        $retry = $queue->pop('collection');
        $this->assertSame($original->uuid(), $retry->uuid());
        $this->assertSame(2, $retry->attempts());
        $retry->delete();
        $this->assertTrue(app(WorkloadQueue::class)->collection($account->id));
    }
}
