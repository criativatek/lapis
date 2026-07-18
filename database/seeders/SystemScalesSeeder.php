<?php

namespace Database\Seeders;

use App\Models\Scale;
use Illuminate\Database\Seeder;

/**
 * The shared scales every organization can use (organization_id NULL).
 *
 * Levels carry codes and labels but NO bands or normalized values — §10.4 forbids
 * inventing the percentage thresholds that map a mark to "Muito Bom". Those are
 * question Q1 and must come from the product owner. Until then a profile using
 * one of these scales shows the raw value; it does not guess a level.
 *
 * Idempotent — safe to re-run.
 */
class SystemScalesSeeder extends Seeder
{
    public function run(): void
    {
        $this->scaleOneToFive();
        $this->scaleZeroToTwenty();
        $this->scalePercentage();
    }

    protected function scaleOneToFive(): void
    {
        $scale = Scale::withoutGlobalScope('scaleVisibility')->updateOrCreate(
            ['organization_id' => null, 'name' => 'Escala 1 a 5'],
            ['kind' => 'level', 'min_value' => 1, 'max_value' => 5],
        );

        $levels = [
            ['code' => '1', 'label' => 'Muito Insuficiente', 'sequence' => 1, 'numeric_value' => 1, 'is_negative' => true],
            ['code' => '2', 'label' => 'Insuficiente', 'sequence' => 2, 'numeric_value' => 2, 'is_negative' => true],
            ['code' => '3', 'label' => 'Suficiente', 'sequence' => 3, 'numeric_value' => 3, 'is_negative' => false],
            ['code' => '4', 'label' => 'Bom', 'sequence' => 4, 'numeric_value' => 4, 'is_negative' => false],
            ['code' => '5', 'label' => 'Muito Bom', 'sequence' => 5, 'numeric_value' => 5, 'is_negative' => false],
        ];

        $this->syncLevels($scale, $levels);
    }

    protected function scaleZeroToTwenty(): void
    {
        $scale = Scale::withoutGlobalScope('scaleVisibility')->updateOrCreate(
            ['organization_id' => null, 'name' => 'Escala 0 a 20'],
            ['kind' => 'numeric', 'min_value' => 0, 'max_value' => 20],
        );

        // A numeric scale has no qualitative levels of its own.
        $scale->levels()->delete();
    }

    protected function scalePercentage(): void
    {
        Scale::withoutGlobalScope('scaleVisibility')->updateOrCreate(
            ['organization_id' => null, 'name' => 'Percentagem (0 a 100)'],
            ['kind' => 'percentage', 'min_value' => 0, 'max_value' => 100],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $levels
     */
    protected function syncLevels(Scale $scale, array $levels): void
    {
        $scale->levels()->delete();

        foreach ($levels as $level) {
            $scale->levels()->create($level + [
                'normalized_value' => null,
                'band_min_normalized' => null,
                'band_max_normalized' => null,
            ]);
        }
    }
}
