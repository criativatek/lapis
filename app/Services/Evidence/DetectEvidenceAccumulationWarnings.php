<?php

namespace App\Services\Evidence;

use App\Models\Enrollment;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\SchoolClass;

/**
 * The accumulation alert (§14, SUP-ZPUQV5): when a student reaches a multiple
 * of 3 logged occurrences of "Atraso" or "Falta de material" IN THIS CLASS,
 * the teacher is told — purely informative, never a change to any record and
 * never anything that enters the calculation (§14.3). It never suggests
 * marking an absence: the corrective step is a behaviour entry in Inovar
 * (grau 2), the one already used to inform/alert the guardian.
 *
 * Counting is per enrollment, over every record of that kind ever logged for
 * it in this class — the class already belongs to one academic year, so this
 * already reads as "no ano letivo" without a separate date window. A
 * class-wide record (enrollment_id null) never contributes to any one
 * student's count ("§ decisão do dono do produto").
 */
class DetectEvidenceAccumulationWarnings
{
    private const int ALERT_THRESHOLD = 3;

    /**
     * The kinds this alert watches for. Every other kind never triggers it.
     *
     * @var list<EvidenceKind>
     */
    private const array ALERTED_KINDS = [EvidenceKind::Lateness, EvidenceKind::MissingMaterial];

    /**
     * @param  list<int|null>  $enrollmentIds  the enrollments a just-saved
     *                                         record of $kind now targets — a
     *                                         null (class-wide) entry is
     *                                         ignored, per product decision.
     * @return list<string> one warning sentence per student who just reached
     *                      a multiple of the threshold, ready to show as-is.
     */
    public function forSavedRecords(SchoolClass $class, EvidenceKind $kind, array $enrollmentIds): array
    {
        if (! in_array($kind, self::ALERTED_KINDS, true)) {
            return [];
        }

        $targetedEnrollmentIds = array_values(array_unique(array_filter(
            $enrollmentIds,
            fn (?int $enrollmentId): bool => $enrollmentId !== null,
        )));

        $warnings = [];

        foreach ($targetedEnrollmentIds as $enrollmentId) {
            $count = EvidenceRecord::query()
                ->forClass($class->id)
                ->where('kind', $kind->value)
                ->where('enrollment_id', $enrollmentId)
                ->count();

            if ($count === 0 || $count % self::ALERT_THRESHOLD !== 0) {
                continue;
            }

            $warnings[] = $this->message($kind, $this->studentName($enrollmentId), $count);
        }

        return $warnings;
    }

    protected function studentName(int $enrollmentId): string
    {
        $enrollment = Enrollment::with('student.identity')->find($enrollmentId);

        return optional($enrollment?->student->identity)->display_name ?? '(sem identidade)';
    }

    protected function message(EvidenceKind $kind, string $studentName, int $count): string
    {
        return match ($kind) {
            EvidenceKind::Lateness => __(
                ':student acumulou :count atrasos. Deverá ser feito um registo de comportamento no Inovar (grau 2) para alertar o encarregado de educação.',
                ['student' => $studentName, 'count' => $count],
            ),
            EvidenceKind::MissingMaterial => __(
                ':student acumulou :count faltas de material. Deverá ser feito um registo de comportamento no Inovar (grau 2) para informar o encarregado de educação das consequências na avaliação.',
                ['student' => $studentName, 'count' => $count],
            ),
            default => '',
        };
    }
}
