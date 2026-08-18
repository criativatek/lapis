<?php

namespace App\Domain\Import;

use App\Models\EnrollmentStatus;
use App\Models\EnrollmentStatusReason;

/**
 * The «SIT.» column of an EB052e/EB058e roll, read as data instead of text.
 *
 * THE FIVE CODES ARE NOT INTERCHANGEABLE. Four of them end an enrolment and
 * every one of them ends it for a different reason: a transfer out of the
 * school, a move to another class, a cancelled enrolment and an exclusion for
 * absences are four different conversations. The importer used to map «TR» and
 * treat everything else as «matriculado», which quietly kept students on a roll
 * they had left.
 *
 * ONE PLACE, because two importers share this file format and a second
 * switch would drift the day the roll gains a sixth code (§25).
 *
 * AN UNKNOWN CODE IS NOT «X». `tryFromCode` returns null for anything it has
 * not been taught, and the caller has to decide what to do about that rather
 * than being handed a default that silently reactivates somebody (§10).
 */
enum EnrollmentSituation: string
{
    case Enrolled = 'X';
    case Transferred = 'TR';
    case MovedClass = 'MT';
    case CancelledEnrolment = 'AM';
    case ExcludedForAbsences = 'EF';

    /** The words a Portuguese roll uses, for the preview and the record. */
    public function label(): string
    {
        return match ($this) {
            self::Enrolled => __('Matriculado'),
            self::Transferred => __('Transferência'),
            self::MovedClass => __('Mudou de turma'),
            self::CancelledEnrolment => __('Anulou matrícula'),
            self::ExcludedForAbsences => __('Excluído por faltas'),
        };
    }

    /**
     * Whether the student is on this roll.
     *
     * «TR» keeps its own status because the application already had one for it
     * and screens read it; the other three end the enrolment under the status
     * the roll already used for leaving, and are told apart by their reason.
     */
    public function status(): EnrollmentStatus
    {
        return match ($this) {
            self::Enrolled => EnrollmentStatus::Active,
            self::Transferred => EnrollmentStatus::TransferredOut,
            self::MovedClass, self::CancelledEnrolment, self::ExcludedForAbsences => EnrollmentStatus::Left,
        };
    }

    /** What happened. Null while the student is still enrolled. */
    public function reason(): ?EnrollmentStatusReason
    {
        return match ($this) {
            self::Enrolled => null,
            self::Transferred => EnrollmentStatusReason::Transfer,
            self::MovedClass => EnrollmentStatusReason::MovedClass,
            self::CancelledEnrolment => EnrollmentStatusReason::CancelledEnrolment,
            self::ExcludedForAbsences => EnrollmentStatusReason::ExcludedForAbsences,
        };
    }

    public function keepsEnrolment(): bool
    {
        return $this === self::Enrolled;
    }

    /**
     * Read a raw cell. Case and surrounding space are forgiven; meaning is not.
     *
     * Returns null for an unknown code AND for an empty cell — «não sei» in
     * both cases, and the caller must not turn either into «matriculado»
     * (§10, §11).
     */
    public static function tryFromCode(?string $code): ?self
    {
        $normalized = mb_strtoupper(trim((string) $code));

        return $normalized === '' ? null : self::tryFrom($normalized);
    }
}
