<?php

namespace App\Shopify\Console;

use App\Models\Client;
use App\Shopify\Models\AppRegistration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

final class ConfigureApp extends Command
{
    protected $signature = 'shopify:configure {slug} {--client=} {--name=} {--shopify-client-id=} {--install-url=} {--data-access-confirmed} {--secret-stdin : Read the client secret from a private stdin pipe}';

    protected $description = 'Configure an existing custom Shopify registration in BS Ads; credentials never appear in the client UI';

    public function handle(): int
    {
        $data = ['slug' => $this->argument('slug'), 'client_id' => $this->option('client'), 'name' => $this->option('name'),
            'client_key' => $this->option('shopify-client-id'), 'install_url' => $this->option('install-url')];
        $validator = Validator::make($data, ['slug' => 'required|regex:/^[a-z0-9-]+$/|max:80', 'client_id' => 'required|integer|exists:clients,id',
            'name' => 'required|string|max:120', 'client_key' => 'required|string|max:191', 'install_url' => ['required', 'url', 'max:2048', function ($attribute, $value, $fail) {
                if (parse_url($value, PHP_URL_SCHEME) !== 'https' || ! in_array(parse_url($value, PHP_URL_HOST), ['admin.shopify.com', 'apps.shopify.com'], true)) {
                    $fail('Use the Shopify-generated installation URL.');
                }
            }]]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        $client = Client::findOrFail($data['client_id']);
        if (! in_array($client->objective, ['ecommerce', 'both'], true)) {
            $this->error('Choose an ecommerce client.');

            return self::FAILURE;
        }
        $app = AppRegistration::where('slug', $data['slug'])->first();
        if (($app && ($app->client_id !== $client->id || $app->client_key !== $data['client_key'] || $app->distribution !== 'custom'))
            || AppRegistration::where('client_key', $data['client_key'])->when($app, fn ($q) => $q->where('id', '!=', $app->id))->exists()) {
            $this->error('The registration identity is already configured differently.');

            return self::FAILURE;
        }
        $secret = $this->option('secret-stdin') ? rtrim((string) fgets(STDIN), "\r\n") : $this->secret('Shopify client secret');
        if (! is_string($secret) || strlen($secret) < 16 || strlen($secret) > 500) {
            $this->error('Enter a valid Shopify client secret.');

            return self::FAILURE;
        }
        // Secret rotation has a separate lifecycle; this command must not invalidate working installations.
        if ($app && $app->secret !== $secret) {
            $this->error('Secret differs from the existing registration. Use the credential rotation workflow.');

            return self::FAILURE;
        }
        AppRegistration::updateOrCreate(['slug' => $data['slug']], array_merge($data, ['client_id' => $client->id,
            'secret' => $secret, 'distribution' => 'custom', 'active' => true, 'data_approved' => (bool) $this->option('data-access-confirmed')]));
        $this->info('Shopify connection configured.');

        return self::SUCCESS;
    }
}
