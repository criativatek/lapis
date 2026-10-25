<?php

namespace App\Support\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicPeriodStatus;
use App\Models\AcademicYear;
use App\Models\Classification;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\User;

/**
 * Who may rectify a published classification, and what the dialog must say (spec §4).
 *
 * THE ORGANIZATION IS THE CLASSIFICATION'S, NEVER THE USER'S "CURRENT" ONE. A
 * teacher owns a personal account and may also be a plain member of a school;
 * which rule applies depends on whose data this is. That is why nothing here
 * reads CurrentOrganization or User::ownsCurrentOrganization().
 *
 * "Closed" is the academic year closed/archived OR the period closed/archived.
 * Draft and open both count as open. While open, only whoever teaches the class
 * rectifies, in any kind of organization (a head who does not teach it does not).
 * Once closed, a personal account still lets the teacher do it, and an
 * institution lets only its head do it — even one who does not teach the class.
 * A closed rectification always asks for an extra confirmation.
 *
 * The server decides everything here; the client never learns organization types.
 */
final class RectificationAccess
{
    private function __construct(
        public readonly bool $allowed,
        public readonly bool $needsConfirmation,
        public readonly bool $closed,
        public readonly ?string $blockedReason,
    ) {}

    public static function for(User $user, Classification $classification): self
    {
        // Only the live, published row can be rectified; anything else is not a
        // question of rights, so there is nothing to explain to the user.
        if (! RectificationContext::isRectifiable($classification)) {
            return self::none();
        }

        // Organization is not tenant-scoped, so this works whatever tenant is resolved.
        $organization = Organization::query()->findOrFail($classification->organization_id);

        // The rows below are read without the tenant scope: the decision has to be
        // the same when the request's tenant is another of the user's organizations,
        // and the scope would then hide them (or throw with no tenant at all).
        $enrollment = Enrollment::query()->withoutGlobalScope('organization')
            ->findOrFail($classification->enrollment_id);
        $class = SchoolClass::query()->withoutGlobalScope('organization')
            ->findOrFail($enrollment->class_id);
        $period = AcademicPeriod::query()->withoutGlobalScope('organization')
            ->findOrFail($classification->academic_period_id);

        return self::context($user, $organization, $class, $period)->accessFor($classification);
    }

    /**
     * What is the same for every classification of one class and period, worked
     * out once: a screen listing thirty students asks this a single time instead
     * of five questions per row.
     */
    public static function context(User $user, Organization $organization, SchoolClass $class, AcademicPeriod $period): RectificationContext
    {
        $year = AcademicYear::query()->withoutGlobalScope('organization')
            ->findOrFail($period->academic_year_id);

        $closed = ! $year->isEditable()
            || in_array($period->status, [AcademicPeriodStatus::Closed, AcademicPeriodStatus::Archived], true);

        return new RectificationContext(
            $closed,
            $class->teachers()->whereKey($user->getKey())->exists(),
            $organization->isPersonal(),
            $user->owns($organization),
        );
    }

    /** Nothing to rectify, nothing to say. */
    public static function none(): self
    {
        return new self(false, false, false, null);
    }

    /** The rule itself, on facts already established. */
    public static function decide(bool $closed, bool $teaches, bool $personal, bool $owns): self
    {
        if (! $closed || $personal) {
            return new self($teaches, $teaches && $closed, $closed, null);
        }

        // Closed, in an institution: the head alone.
        if ($owns) {
            return new self(true, true, true, null);
        }

        // Only a teacher of the class is shown the reason; anyone else never sees the button.
        return new self(false, false, true, $teaches
            ? __('O período está encerrado. Só o responsável da instituição pode retificar.')
            : null);
    }
}
