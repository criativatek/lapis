<?php

namespace App\Actions\Interventions;

use App\Models\Intervention;
use App\Models\LegalMappingSource;
use App\Models\SchoolClass;
use App\Models\User;

/**
 * The mechanical core of writing a new Intervention — the part that was
 * duplicated between InterventionController::store() (a teacher filling the
 * form) and the characterisation import (a teacher confirming a preview row).
 *
 * WHAT THIS DOES NOT DO: decide anything. It does not resolve a legal
 * framework, does not read a request, does not compute a designation from a
 * type, does not decide what "purpose" or "domain_relation" should be. Every
 * one of those is a decision that differs by caller — the form asks the
 * teacher, the import asks the preview the teacher already confirmed — and
 * stays in the caller so the two are never forced to agree on inputs neither
 * of them actually has. What is identical between them, and lived here before
 * only inside `InterventionController::store()`'s loop, is: create the row,
 * attach the participants, write the typed support-measure pairs, and stamp
 * the row's top-level (level, code) from the first of them — the same
 * convention `syncSupportMeasures()` uses for an edit.
 *
 * Audit recording is deliberately left to the caller. The controller records
 * `intervention.created` once per intervention, AFTER its transaction commits,
 * with a `created_batch_ulid` property this class knows nothing about; moving
 * that here would change WHEN and with WHAT shape the event fires, which is
 * exactly the behaviour change the brief asked this extraction to avoid.
 */
class CreateIntervention
{
    /**
     * @param  array<string, mixed>  $attributes  Every Intervention column already
     *                                            resolved by the caller (type, framing,
     *                                            reasoning, dates, …). `class_id` and
     *                                            `created_by` are set here and must not
     *                                            be passed in.
     * @param  list<array{level: string, code: string}>  $supportMeasures  Typed pairs to
     *                                                                     write as InterventionSupportMeasure rows.
     *                                                                     An empty list writes none and leaves the
     *                                                                     top-level support_measure_* columns exactly
     *                                                                     as `$attributes` set them — which is what a
     *                                                                     caller that manages its own measures (the
     *                                                                     controller, via syncSupportMeasures()) wants.
     * @param  list<int>  $participantIds
     */
    public function create(
        SchoolClass $class,
        array $attributes,
        array $supportMeasures,
        array $participantIds,
        User $createdBy,
    ): Intervention {
        $intervention = Intervention::create([
            'class_id' => $class->id,
            ...$attributes,
            'created_by' => $createdBy->getKey(),
        ]);

        $intervention->participants()->sync($participantIds);

        if ($supportMeasures !== []) {
            $mappingSource = $attributes['legal_mapping_source'] ?? LegalMappingSource::Manual;
            $frameworkCode = $attributes['legal_framework_code'] ?? null;

            foreach ($supportMeasures as $pair) {
                $intervention->supportMeasures()->create([
                    'support_measure_level' => $pair['level'],
                    'support_measure_code' => $pair['code'],
                    'legal_mapping_source' => $mappingSource,
                    'legal_framework_code' => $frameworkCode,
                ]);
            }

            $first = $supportMeasures[0];
            $intervention->forceFill([
                'support_measure_level' => $first['level'],
                'support_measure_code' => $first['code'],
            ])->save();
        }

        return $intervention;
    }
}
