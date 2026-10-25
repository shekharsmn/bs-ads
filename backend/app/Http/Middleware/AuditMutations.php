<?php

namespace App\Http\Middleware;

use App\Models\AuditEvent;
use App\Services\AuditTrail;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditMutations
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function handle(Request $request, Closure $next)
    {
        if (! in_array(strtoupper($request->method()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }
        $correlationId = (string) Str::uuid();
        $request->attributes->set('correlation_id', $correlationId);
        $response = $next($request);
        if ($response->getStatusCode() < 400 && ! AuditEvent::where('correlation_id', $correlationId)->exists()) {
            $entity = collect($request->route()?->parameters() ?? [])->first(fn ($value) => $value instanceof Model);
            $clientId = $request->integer('client_id') ?: ($entity?->client_id ?? null);
            $this->audit->record('api.'.strtolower($request->method()).'.'.str_replace('/', '.', $request->route()?->uri() ?? 'unknown'), $entity, [], $request->except(['password', 'password_confirmation']), $clientId ?: null, $request->user(), $request);
        }

        return $response;
    }
}
