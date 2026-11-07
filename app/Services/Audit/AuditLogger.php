<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class AuditLogger
{
    private const REDACT = ['password', 'access_token', 'refresh_token', 'token', 'secret'];

    public function __construct(private TenantContext $tenant) {}

    /**
     * @param  array<string, mixed>  $changes
     */
    public function log(
        string $action,
        ?Model $subject = null,
        array $changes = [],
        ?string $actorType = null,
        ?string $actorId = null,
        ?string $organizationId = null,
    ): AuditLog {
        $user = auth()->user();
        $request = app()->runningInConsole() ? null : request();

        return AuditLog::withoutGlobalScopes()->create([
            'organization_id' => $organizationId ?? $subject?->getAttribute('organization_id') ?? $this->tenant->organizationId(),
            'actor_type' => $actorType ?? ($user ? 'user' : 'system'),
            'actor_id' => $actorId ?? ($user ? (string) $user->getAuthIdentifier() : null),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject ? (string) $subject->getKey() : null,
            'changes' => $changes ? $this->redact($changes) : null,
            'ip' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 500) : null,
            'correlation_id' => request()?->attributes->get('correlation_id'),
        ]);
    }

    private function redact(array $changes): array
    {
        foreach (Arr::dot($changes) as $key => $value) {
            foreach (self::REDACT as $needle) {
                if (str_contains(strtolower((string) $key), $needle)) {
                    Arr::set($changes, $key, '[redacted]');
                }
            }
        }

        return $changes;
    }
}
