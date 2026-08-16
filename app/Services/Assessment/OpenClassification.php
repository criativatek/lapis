<?php

namespace App\Services\Assessment;

use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Support\Assessment\ClassificationDecisionException;
use Illuminate\Support\Facades\DB;

/**
 * The row a decision is written on, found or opened.
 *
 * A teacher's decision must not wait on a proposal. ProposeClassifications
 * writes a row only where the engine reached a value — which is right for a
 * PROPOSAL, and wrong as a precondition for a teacher: a period whose proposals
 * were never generated, or a student whose result is not computable, are both
 * cases where the teacher may still have a classification to assign, and the
 * absence of a row was standing in the way of them assigning it.
 *
 * What is opened here is EMPTY on purpose. The proposal columns stay null,
 * because nothing was proposed; the status is `proposed` in the sense of «no
 * decision yet», which is what that state has always meant. If the proposals are
 * generated later, ProposeClassifications finds this very row and fills them in
 * — it looks for the live row of the (enrolment, period, scope), which is
 * exactly what this returns.
 */
class OpenClassification
{
    public function forDecision(
        SchoolClass $class,
        AcademicPeriod $period,
        Enrollment $enrollment,
        ClassificationScope $scope = ClassificationScope::Period,
    ): Classification {
        $live = Classification::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('academic_period_id', $period->id)
            ->where('scope', $scope)
            ->whereNot('status', ClassificationStatus::Superseded)
            ->first();

        if ($live !== null) {
            return $live;
        }

        // Nothing to classify on: a class with no assessment profile has no
        // scale, and there is no answer to give (§10.4).
        if ($class->assessment_profile_version_id === null) {
            throw ClassificationDecisionException::withoutScale();
        }

        return DB::transaction(fn (): Classification => Classification::create([
            'enrollment_id' => $enrollment->id,
            'academic_period_id' => $period->id,
            'scope' => $scope,
            'assessment_profile_version_id' => $class->assessment_profile_version_id,
            'status' => ClassificationStatus::Proposed,
        ]));
    }
}
