<?php

namespace App\Support\Interventions;

use App\Models\Intervention;

/**
 * The base `intervention.created` / `intervention.updated` property set,
 * shared by every writer of that event.
 *
 * Before this existed, `InterventionController::record()` and
 * `ApplyCharacterisationImport::createInterventions()` each built the audit
 * payload by hand, and drifted: the import's shape omitted `target_type`,
 * `participants`, `intervention_type` and `status` that the controller's path
 * always carried. Nothing pinned either shape, so nothing caught it. This
 * class is the one place both now read the base shape from — a caller merges
 * ITS OWN extra keys on top (`created_batch_ulid`, `import_batch_ulid`, …),
 * which is exactly the only thing that legitimately differs between the two
 * paths.
 */
final class InterventionAuditProperties
{
    /**
     * @return array<string, mixed>
     */
    public static function base(Intervention $intervention): array
    {
        return [
            'class_id' => (int) $intervention->class_id,
            'target_type' => $intervention->target_type->value,
            'participants' => $intervention->participants()->count(),
            'intervention_type' => $intervention->intervention_type?->value,
            'status' => $intervention->status->value,
        ];
    }
}
