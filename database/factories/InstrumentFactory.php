<?php

namespace Database\Factories;

use App\Models\AcademicPeriod;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\Organization;
use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Instrument>
 *
 * Share the organization with `->recycle($organization)`.
 */
class InstrumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'class_id' => SchoolClass::factory(),
            'academic_period_id' => AcademicPeriod::factory(),
            'instrument_type_id' => fn () => InstrumentType::withoutGlobalScope('typeVisibility')
                ->firstOrCreate(['organization_id' => null, 'code' => 'TEST'], ['name' => 'Teste global', 'default_purpose' => 'summative'])->id,
            'title' => 'Teste de Compreensão Leitora',
            'applied_on' => '2026-10-15',
            'status' => 'in_correction',
            'counts_toward_classification' => true,
            'purpose' => 'summative',
            'total_points' => 100,
            'allow_bonus' => false,
        ];
    }
}
