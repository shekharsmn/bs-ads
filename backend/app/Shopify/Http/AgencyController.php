<?php

namespace App\Shopify\Http;

use App\Models\Client;
use App\Services\ClientAccess;
use App\Shopify\CommerceReadService;
use App\Shopify\InstallationService;
use App\Shopify\LinkService;
use App\Shopify\Models\AppRegistration;
use App\Shopify\Models\Store;
use App\Shopify\SyncCoordinator;
use Illuminate\Http\Request;

final class AgencyController
{
    public function updateRegistration(Request $r, AppRegistration $registration)
    {
        $data = $r->validate(['data_approved' => 'sometimes|boolean', 'active' => 'sometimes|boolean', 'secret' => 'sometimes|string|min:16|max:500', 'retire_previous_secret' => 'sometimes|boolean']);
        if (isset($data['secret'])) {
            $data['previous_secret'] = $registration->secret;
        }
        if ($data['retire_previous_secret'] ?? false) {
            $data['previous_secret'] = null;
        }
        unset($data['retire_previous_secret']);
        $registration->update($data);
        if (isset($data['secret'])) {
            foreach ($registration->installs as $install) {
                $install->update(['status' => 'reconnect', 'tokens' => null, 'error_code' => 'secret_rotated']);
            }
        }

        return $registration;
    }

    public function registrations(Request $r)
    {
        if ($r->isMethod('GET')) {
            return AppRegistration::orderBy('name')->get();
        }
        $data = $r->validate(['slug' => 'required|regex:/^[a-z0-9-]+$/|max:80|unique:shop_apps,slug', 'name' => 'required|string|max:120',
            'client_key' => 'required|string|max:191|unique:shop_apps,client_key', 'secret' => 'required|string|min:16|max:500',
            'distribution' => 'required|in:custom,public', 'client_id' => 'nullable|integer|exists:clients,id', 'data_approved' => 'required|boolean',
            'install_url' => ['required', 'url', 'max:2048', function ($attribute, $value, $fail) {
                if (parse_url($value, PHP_URL_SCHEME) !== 'https' || ! in_array(parse_url($value, PHP_URL_HOST), ['admin.shopify.com', 'apps.shopify.com'], true)) {
                    $fail('Use the Shopify-generated installation or listing URL.');
                }
            }]]);
        if ($data['distribution'] === 'custom' && empty($data['client_id'])) {
            abort(422, 'Custom registrations need an intended client.');
        }
        if ($data['distribution'] === 'public' && ! empty($data['client_id'])) {
            abort(422, 'Public registrations are not restricted to one client.');
        }

        return response()->json(AppRegistration::create($data), 201);
    }

    public function connection(Request $r, Client $client)
    {
        ClientAccess::authorize($r->user(), $client);

        return ['store' => Store::where('client_id', $client->id)->first(),
            'registrations' => $r->user()->isAdmin() ? AppRegistration::where('active', true)->where(fn ($q) => $q->where('client_id', $client->id)->orWhere('distribution', 'public'))->get(['id', 'name', 'slug', 'install_url', 'client_id']) : []];
    }

    public function connect(Request $r, Client $client)
    {
        ClientAccess::authorize($r->user(), $client, true);
        abort_unless(in_array($client->objective, ['ecommerce', 'both'], true), 422, 'Shopify requires an ecommerce client.');
        $apps = AppRegistration::where('active', true)->where('distribution', 'custom')->where('client_id', $client->id)->get();
        abort_if($apps->isEmpty(), 409, 'Shopify has not been configured for this client.');
        abort_unless($apps->count() === 1, 409, 'Multiple Shopify registrations are configured for this client.');
        $app = $apps->first();
        abort_unless($app->data_approved && $app->install_url, 409, 'Shopify setup is incomplete for this client.');

        return ['url' => $app->install_url];
    }

    public function report(Request $r, Client $client, CommerceReadService $reports)
    {
        ClientAccess::authorize($r->user(), $client);
        $store = Store::where('client_id', $client->id)->first();

        return $store ? $reports->report($store, $r->query()) : ['store' => null, 'warnings' => ['No Shopify store is connected.']];
    }

    public function summary(Request $r, CommerceReadService $reports)
    {
        $ids = ClientAccess::clientsFor($r->user())->select('id');

        return ['stores' => Store::whereIn('client_id', $ids)->orderBy('id')->get()->map(fn ($s) => $reports->report($s, $r->query()))];
    }

    public function link(Request $r, Client $client, LinkService $links)
    {
        ClientAccess::authorize($r->user(), $client, true);
        $data = $r->validate(['replace' => 'sometimes|boolean']);

        return response()->json(['token' => $links->create($client, $r->user(), $data['replace'] ?? false), 'expires_in' => 900], 201);
    }

    public function refresh(Request $r, Client $client, SyncCoordinator $sync)
    {
        ClientAccess::authorize($r->user(), $client, true);
        $install = Store::where('client_id', $client->id)->firstOrFail()->installation;
        abort_unless($install, 409, 'No active installation.');

        return response()->json(['run' => $sync->enqueue($install)], 202);
    }

    public function disconnect(Request $r, Client $client, InstallationService $installs)
    {
        ClientAccess::authorize($r->user(), $client, true);
        $store = Store::where('client_id', $client->id)->firstOrFail();
        if ($store->installation) {
            $installs->revoke($store->installation);
        }
        $store->update(['client_id' => null]);

        return response()->noContent();
    }
}
