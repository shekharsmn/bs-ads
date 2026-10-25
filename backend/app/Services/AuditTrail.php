<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditTrail
{
    private const SENSITIVE = ['password', 'password_confirmation', 'token', 'access_token', 'refresh_token', 'app_secret', 'client_secret', 'credentials', 'configuration', 'google_chat_webhook', 'webhook', 'secret'];

    public function record(string $action, Model|string|null $entity = null, array $before = [], array $after = [], ?int $clientId = null, ?User $actor = null, ?Request $request = null): AuditEvent
    {
        $request ??= request();

        return AuditEvent::create([
            'actor_id' => ($actor ?? $request?->user())?->id,
            'client_id' => $clientId,
            'action' => $action,
            'entity_type' => $entity instanceof Model ? $entity::class : $entity,
            'entity_id' => $entity instanceof Model ? (string) $entity->getKey() : null,
            'source' => app()->runningInConsole() ? 'job' : 'http',
            'correlation_id' => (string) ($request?->attributes->get('correlation_id') ?: Str::uuid()),
            'before' => $this->sanitize($before) ?: null,
            'after' => $this->sanitize($after) ?: null,
            'ip_address' => app()->runningInConsole() ? null : $request?->ip(),
            'user_agent' => app()->runningInConsole() ? null : Str::limit((string) $request?->userAgent(), 500, ''),
        ]);
    }

    public function sanitize(array $values): array
    {
        return collect($values)->mapWithKeys(function ($value, $key) {
            if (in_array(strtolower((string) $key), self::SENSITIVE, true) || Str::contains(strtolower((string) $key), ['token', 'secret', 'password', 'webhook'])) {
                return [$key => '[redacted]'];
            }
            if (is_array($value)) {
                return [$key => $this->sanitize($value)];
            }
            if (is_string($value) && strlen($value) > 2000) {
                return [$key => Str::limit($value, 2000)];
            }

            return [$key => $value];
        })->all();
    }
}
