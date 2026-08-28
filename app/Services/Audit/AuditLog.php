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

    /**
     * An event that belongs to the PLATFORM rather than to any organization.
     *
     * FOR THE BACKOFFICE, AND ONLY FOR IT. Turning the AI engine on, changing
     * the model, replacing the credential — these are acts of the SaaS operator
     * that affect every tenant and belong to none (§9 of the AI Core brief).
     * Filing them under whichever organization the acting admin happens to own
     * would put a platform-wide decision inside one teacher's audit log, where
     * its owner can read it and where it is simply not true.
     *
     * `forceFill`, NOT `create`, AND THAT IS THE POINT. `organization_id` is
     * deliberately absent from `AuditEvent`'s `#[Fillable]` list: a model that
     * accepted a tenant id by mass assignment is a model that can be made to
     * write into another tenant. Setting it explicitly here means the ONE place
     * that may write a tenant-less audit row is this method, and it is greppable.
     *
     * The row is invisible to every tenant query for free — `BelongsToOrganization`
     * scopes to `organization_id = <id>`, and NULL is never equal to a number.
     *
     * NO SUBJECT. There is no model to point at: the platform settings row is a
     * singleton nobody navigates to, and pointing at it would say less than the
     * event name already does.
     *
     * @param  array<string, mixed>  $properties
     */
    public function recordPlatform(
        string $event,
        ?User $causer = null,
        ?string $summary = null,
        array $properties = [],
    ): AuditEvent {
        $causer ??= auth()->user();

        $row = new AuditEvent;

        $row->forceFill([
            'organization_id' => null,
            'causer_id' => $causer?->getKey(),
            'event' => $event,
            'summary' => $summary,
            'properties' => $properties === [] ? null : $properties,
            'created_at' => now(),
        ]);

        $row->save();

        return $row;
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
