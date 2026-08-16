<?php

namespace Database\Seeders;

use App\Models\Scale;
use Illuminate\Database\Seeder;

/**
 * The shared scales every organization can use (organization_id NULL).
 *
 * The 1-to-5 scale carries the product-approved normalized bands. Numeric system
 * scales have no qualitative levels and continue to expose their raw value.
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
            ['code' => '1', 'inovar_code' => 'F', 'label' => 'Fraco', 'sequence' => 1, 'numeric_value' => 1, 'is_negative' => true, 'band_min_normalized' => '0.000000', 'band_max_normalized' => '19.499999'],
            ['code' => '2', 'inovar_code' => 'I', 'label' => 'Insuficiente', 'sequence' => 2, 'numeric_value' => 2, 'is_negative' => true, 'band_min_normalized' => '19.500000', 'band_max_normalized' => '49.499999'],
            ['code' => '3', 'inovar_code' => 'S', 'label' => 'Suficiente', 'sequence' => 3, 'numeric_value' => 3, 'is_negative' => false, 'band_min_normalized' => '49.500000', 'band_max_normalized' => '69.499999'],
            ['code' => '4', 'inovar_code' => 'B', 'label' => 'Bom', 'sequence' => 4, 'numeric_value' => 4, 'is_negative' => false, 'band_min_normalized' => '69.500000', 'band_max_normalized' => '89.499999'],
            ['code' => '5', 'inovar_code' => 'MB', 'label' => 'Muito Bom', 'sequence' => 5, 'numeric_value' => 5, 'is_negative' => false, 'band_min_normalized' => '89.500000', 'band_max_normalized' => '100.000000'],
        ];

        $this->syncLevels($scale, $levels);
    }

    protected function scaleZeroToTwenty(): void
    {
        // A numeric scale has no qualitative levels OF ITS OWN — this seeder
        // defines none for it.
        //
        // It used to go further and delete any it found, which is the same
        // mistake syncLevels was fixed for, left standing in the one place the
        // fix did not reach. A numeric scale CAN legitimately carry bands
        // (§10.4, and ScaleProposalResolver resolves them), so "seeds none" is
        // not a licence to remove what an installation already has: the delete
        // is either refused by a foreign key — stopping the whole seeder on
        // exactly the installations that have been in use — or it takes rows a
        // recorded classification points at.
        Scale::withoutGlobalScope('scaleVisibility')->updateOrCreate(
            ['organization_id' => null, 'name' => 'Escala 0 a 20'],
            ['kind' => 'numeric', 'min_value' => 0, 'max_value' => 20],
        );
    }

    protected function scalePercentage(): void
    {
        Scale::withoutGlobalScope('scaleVisibility')->updateOrCreate(
            ['organization_id' => null, 'name' => 'Percentagem (0 a 100)'],
            ['kind' => 'percentage', 'min_value' => 0, 'max_value' => 100],
        );
    }

    /**
     * Brings each band up to date IN PLACE, keyed on the code it already has.
     *
     * It used to delete every level and write them again, which works exactly
     * once: as soon as a classification, a self-assessment answer or a snapshot
     * points at a band, the delete is refused by the foreign key and this seeder
     * stops running — on precisely the installations that have been in use. And
     * were it not refused, it would take the rows those records point at.
     *
     * Updating instead means reference data can be corrected — a band gaining
     * its INOVAR correspondence, say — without touching a single decision that
     * was already recorded against it. A band dropped from the definition here
     * is deliberately left alone rather than deleted, for the same reason.
     *
     * @param  list<array<string, mixed>>  $levels
     */
    protected function syncLevels(Scale $scale, array $levels): void
    {
        foreach ($levels as $level) {
            $scale->levels()->updateOrCreate(
                ['code' => $level['code']],
                $level + ['normalized_value' => null],
            );
        }
    }
}
