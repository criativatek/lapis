<?php

namespace App\Services\Audit;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Records the audit trail (§22.4). Callers name a namespaced event (e.g.
 * `classification.confirmed`) and hand the affected record; this stamps the
 * causer, the tenant and the moment. The full set of mandatory events (§22.5) is
 * emitted from the services that own each action, not from a model observer, so
 * the trail carries intent (a reason, the before/after) rather than a raw diff.
 */
class AuditLog
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(
        string $event,
        ?Model $subject = null,
        ?User $causer = null,
        ?string $summary = null,
        array $properties = [],
    ): AuditEvent {
        $causer ??= auth()->user();

        return AuditEvent::create([
            'causer_id' => $causer?->getKey(),
            'event' => $event,
            'subject_type' => $subject === null ? null : class_basename($subject),
            'subject_id' => $subject?->getKey(),
            'subject_ulid' => $this->ulidOf($subject),
            'summary' => $summary,
            'properties' => $properties === [] ? null : $properties,
            'created_at' => now(),
        ]);
    }

    protected function ulidOf(?Model $subject): ?string
    {
        if ($subject === null || ! array_key_exists('ulid', $subject->getAttributes())) {
            return null;
        }

        $ulid = $subject->getAttribute('ulid');

        return is_string($ulid) ? $ulid : null;
    }
}
