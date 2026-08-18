<?php

namespace App\Models;

/**
 * WHY an enrolment is no longer active — beside `status`, never instead of it.
 *
 * The four ways a student leaves a class in a Portuguese school roll are not
 * the same event, and a roll that recorded only «saiu» would lose the
 * difference between a student who moved to the class next door and one whose
 * enrolment was cancelled. `status` answers «is this student on this roll»,
 * which is what every screen filters by; this answers «what happened», which is
 * what a secretaria and a conselho de turma actually discuss.
 *
 * NONE OF THESE IS A GRADE. Being excluded for absences is an administrative
 * state: it produces no classification, no zero and no absence in any
 * instrument. Nothing in the assessment engine reads this enum (§9, §27).
 */
enum EnrollmentStatusReason: string
{
    /** «TR» on an EB052e/EB058e roll. */
    case Transfer = 'transfer';

    /** «MT» — moved to another class, NOT a transfer out of the school. */
    case MovedClass = 'moved_class';

    /** «AM» — the enrolment itself was cancelled. */
    case CancelledEnrolment = 'cancelled_enrolment';

    /** «EF» — excluded for absences. Administrative, never pedagogical. */
    case ExcludedForAbsences = 'excluded_absences';

    public function label(): string
    {
        return match ($this) {
            self::Transfer => __('Transferência'),
            self::MovedClass => __('Mudou de turma'),
            self::CancelledEnrolment => __('Anulou matrícula'),
            self::ExcludedForAbsences => __('Excluído por faltas'),
        };
    }
}
