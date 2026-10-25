<?php

namespace App\Services\Assessment;

use App\Models\Classification;
use App\Models\Enrollment;
use App\Models\EvaluationSheetExport;

/**
 * What the teacher must be told before rectifying a published classification
 * (spec §7).
 *
 * A published classification has, by definition, been communicated. Inside
 * Lapispro the rectification fixes every reader at once; outside it — Inovar, a
 * printed pauta, a PDF — nothing is fixed, and the teacher has to do it by hand.
 * So the generic sentence is always shown.
 *
 * THE ONLY TRACE OF AN EXTERNAL COMMUNICATION is an EvaluationSheetExport. It
 * counts only if it is a photograph of THIS class, period and scope taken at or
 * after the publication, and if it holds THIS student: a pauta kept for another
 * class, period or scope, or before the grade existed, never carried this value.
 * The student is matched by `payload.students[].enrollment_id`, which
 * BuildEvaluationSheet writes for every row and which does not change.
 */
class ExternalCommunicationNotice
{
    public const GENERIC = 'Esta classificação já foi publicada. Se a comunicou fora do Lapispro (Inovar, pauta impressa, PDF), terá de atualizar esses registos.';

    /**
     * @return array{generic: string, export: ?array{at: string, adapter: string, moment_label: string}}
     */
    public function for(Classification $classification): array
    {
        return ['generic' => self::GENERIC, 'export' => $this->latestExport($classification)];
    }

    /**
     * @return array{at: string, adapter: string, moment_label: string}|null
     */
    protected function latestExport(Classification $classification): ?array
    {
        if ($classification->published_at === null) {
            return null;
        }

        // Read without the tenant scope: the notice must be the same whichever of
        // the user's organizations is current (same approach as RectificationAccess).
        // Everything is then keyed by ids the classification itself carries.
        $classId = Enrollment::query()->withoutGlobalScope('organization')
            ->whereKey($classification->enrollment_id)
            ->value('class_id');

        if ($classId === null) {
            return null;
        }

        $candidates = EvaluationSheetExport::query()->withoutGlobalScope('organization')
            ->where('organization_id', $classification->organization_id)
            ->where('class_id', $classId)
            ->where('academic_period_id', $classification->academic_period_id)
            ->where('scope', $classification->scope)
            ->where('exported_at', '>=', $classification->published_at)
            ->orderByDesc('exported_at')
            ->orderByDesc('id')
            ->get();

        foreach ($candidates as $export) {
            $students = is_array($export->payload['students'] ?? null) ? $export->payload['students'] : [];
            $holdsStudent = collect($students)
                ->contains(fn ($student) => (int) ($student['enrollment_id'] ?? 0) === (int) $classification->enrollment_id);

            if ($holdsStudent) {
                return [
                    'at' => $export->exported_at->toIso8601String(),
                    'adapter' => $export->adapter,
                    'moment_label' => $export->moment_label,
                ];
            }
        }

        return null;
    }
}
