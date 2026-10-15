<?php

namespace App\Http\Controllers\Api;

use App\Enums\CanonicalMetric;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ConversionMapping;
use App\Services\ClientAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

class ConversionMappingController extends Controller
{
    public function index(Request $request, Client $client)
    {
        ClientAccess::authorize($request->user(), $client);
        return ConversionMapping::where('client_id', $client->id)->orderByDesc('version')->orderBy('external_action')->get();
    }

    public function store(Request $request, Client $client)
    {
        ClientAccess::authorize($request->user(), $client, true);
        $data = $request->validate(['platform' => 'required|in:google,meta', 'external_action' => 'required|string|max:255', 'canonical_metric' => ['required', new Enum(CanonicalMetric::class)], 'weight' => 'sometimes|numeric|min:0', 'active' => 'sometimes|boolean']);
        $version = ((int) ConversionMapping::where('client_id', $client->id)->where('platform', $data['platform'])->max('version')) + 1;
        return response()->json(ConversionMapping::create($data + ['client_id' => $client->id, 'version' => $version]), 201);
    }

    public function destroy(Request $request, Client $client, ConversionMapping $mapping)
    {
        ClientAccess::authorize($request->user(), $client, true);
        abort_unless($mapping->client_id === $client->id, 404);
        $mapping->delete();
        return response()->noContent();
    }
}
