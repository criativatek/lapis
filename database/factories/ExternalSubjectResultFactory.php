<?php

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\ExternalSubjectResult;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExternalSubjectResult>
 */
class ExternalSubjectResultFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'enrollment_id' => Enrollment::factory(),
            'period_id' => null,
            // «PLNM» é o caso que motivou esta tabela, e é texto livre de
            // propósito: a origem é o percurso alternativo que a escola
            // conhece, e não um código deste domínio (ver a migração).
            'origin' => 'PLNM',
            'scale_level_id' => null,
            'level_code' => '4',
            'numeric_value' => null,
            'recorded_on' => '2026-09-14',
            'note' => null,
        ];
    }
}
