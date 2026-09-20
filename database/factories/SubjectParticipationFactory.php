<?php

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\SubjectParticipation;
use App\Models\SubjectParticipationReason;
use App\Models\SubjectParticipationState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubjectParticipation>
 */
class SubjectParticipationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'enrollment_id' => Enrollment::factory(),
            'state' => SubjectParticipationState::NotAttending,
            'reason' => SubjectParticipationReason::AlternativeSubject,
            'reason_detail' => 'PLNM',
            'note' => null,
            'effective_from' => '2026-09-14',
            'effective_until' => null,
        ];
    }
}
