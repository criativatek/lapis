<?php

namespace Database\Factories;

use App\Models\AcademicCalendarException;
use App\Models\AcademicCalendarExceptionSource;
use App\Models\AcademicCalendarExceptionType;
use App\Models\AcademicYear;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicCalendarException>
 *
 * An exception must share its organization with its year. In tests, use
 * `->recycle($organization)` so both relationships resolve to the same one:
 * `AcademicCalendarException::factory()->recycle($organization)->for($year)->create()`
 * — the same convention AcademicPeriodFactory already documents.
 *
 * O padrão por omissão é o caso mais comum de todos: um feriado de UM dia,
 * `starts_on === ends_on`.
 */
class AcademicCalendarExceptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'type' => AcademicCalendarExceptionType::Holiday,
            'title' => 'Implantação da República',
            'starts_on' => '2026-10-05',
            'ends_on' => '2026-10-05',
            'note' => null,
            'source' => AcademicCalendarExceptionSource::Manual,
        ];
    }
}
