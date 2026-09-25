<?php

namespace App\Domain\Assessment\Analysis;

/**
 * The state of one student's observation within an analysis dimension
 * (global or one domain), derived from the calculation engine's explanation —
 * never a second reading of the cells (design spec §3.2).
 *
 * `classified` is the only status with a value. `out_of_scope` sits outside
 * the universe entirely (the student did not attend the class/subject on the
 * date). Every other status is a form of "not classified", kept apart so the
 * missing breakdown can say which.
 */
enum ObservationStatus: string
{
    case Classified = 'classified';
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case Absent = 'absent';
    case AbsentJustified = 'absent_justified';
    case Exempt = 'exempt';
    case NotApplicable = 'not_applicable';
    case Annulled = 'annulled';
    case OutOfScope = 'out_of_scope';

    public function label(): string
    {
        return match ($this) {
            self::Classified => 'Avaliado',
            self::Pending => 'Por classificar',
            self::UnderReview => 'Em revisão',
            self::Absent => 'Ausente',
            self::AbsentJustified => 'Ausência justificada',
            self::Exempt => 'Dispensado',
            self::NotApplicable => 'Não aplicável',
            self::Annulled => 'Anulado',
            self::OutOfScope => 'Não abrangido',
        };
    }
}
