<?php

namespace Tests\Feature\Database;

use App\Models\Classification;
use App\Models\Organization;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\ProposeClassifications;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * That an installation ALREADY IN USE survives the upgrade.
 *
 * Reference data is the one thing a release re-runs over a live database, and a
 * scale level is not a row anybody can afford to lose: a classification points
 * at it, and that classification is a teacher's recorded decision about a real
 * student. Rewriting reference data therefore has exactly one safe shape —
 * correct what the system owns, in place, and touch nothing else.
 *
 * These are upgrade tests, not unit tests. The state each builds is the state a
 * school has: rows with ids that other rows already reference, a scale the
 * school made for itself, and grades that were confirmed months ago.
 */
class ReferenceDataUpgradeTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------- 1. a migration

    #[Test]
    public function the_column_is_nullable_and_carries_no_default(): void
    {
        $this->assertTrue(Schema::hasColumn('scale_levels', 'inovar_code'));

        // A band with no INOVAR correspondence must be storable — most scales
        // have none, and a default would hand every one of them a code nobody
        // chose. The export refuses a scale without codes; it never guesses.
        $scale = $this->customScale();
        $level = $scale->levels()->create(['code' => 'X', 'label' => 'Sem correspondência', 'sequence' => 1]);

        $this->assertNull($level->fresh()->inovar_code);

        // Long enough for the longest code the grid uses.
        $level->update(['inovar_code' => 'MB']);
        $this->assertSame('MB', $level->fresh()->inovar_code);
    }

    #[Test]
    public function the_migration_adds_capacity_and_decides_nothing(): void
    {
        $source = (string) file_get_contents(
            database_path('migrations/2026_08_16_000400_add_inovar_code_to_scale_levels.php'),
        );

        $this->assertStringContainsString("string('inovar_code', 8)->nullable()", $source);

        // No backfill of any kind. A migration that wrote «sequence 4 → B» or
        // «label Bom → B» would be deciding the mapping from something that is
        // not the mapping — and would do it once, silently, on live data.
        foreach (['update(', 'DB::', 'default(', 'sequence', 'label'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $this->phpOf($source), "a migration não pode conter «{$forbidden}»");
        }

        // Reversible.
        $this->assertStringContainsString("dropColumn('inovar_code')", $source);
    }

    #[Test]
    public function the_column_can_be_dropped_and_re_added_over_populated_data(): void
    {
        $identifiers = $this->levelIdentifiers();
        $this->assertNotEmpty($identifiers);

        $migration = require database_path('migrations/2026_08_16_000400_add_inovar_code_to_scale_levels.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('scale_levels', 'inovar_code'));

        $migration->up();

        // Same rows, same ids — the column comes and goes, the data does not.
        $this->assertSame($identifiers, $this->levelIdentifiers());
        $this->assertNull(ScaleLevel::query()->whereNotNull('inovar_code')->first());
    }

    // ------------------------------------- 2. instalação nova vs existente

    #[Test]
    public function a_new_installation_gets_the_system_scales_and_their_codes(): void
    {
        // RefreshDatabase already migrated from empty and ran ReferenceDataSeeder
        // — this IS the fresh-install path.
        $this->assertSame(
            ['1' => 'F', '2' => 'I', '3' => 'S', '4' => 'B', '5' => 'MB'],
            $this->codesOf('Escala 1 a 5'),
        );

        foreach (['Escala 0 a 20', 'Percentagem (0 a 100)'] as $name) {
            $this->assertNotNull($this->systemScale($name));
        }
    }

    #[Test]
    public function an_installation_already_in_use_keeps_every_row_it_had(): void
    {
        // THE TEST THIS TASK EXISTS FOR. The state below is a school's: system
        // levels with ids other tables point at, a scale the school authored,
        // and a grade a teacher confirmed — all of it predating the column.
        $custom = $this->customScaleWithLevels();
        $classification = $this->aConfirmedGrade();
        $this->pretendTheColumnIsNew();

        $before = $this->wholeState();

        $this->seed(ReferenceDataSeeder::class);

        $after = $this->wholeState();

        // Everything except the INOVAR codes is identical, row by row and
        // column by column.
        $this->assertSame($this->withoutInovarCodes($before), $this->withoutInovarCodes($after));

        // And the codes are the only thing that appeared.
        $this->assertSame(
            ['1' => 'F', '2' => 'I', '3' => 'S', '4' => 'B', '5' => 'MB'],
            $this->codesOf('Escala 1 a 5'),
        );

        $this->assertCustomScaleUntouched($custom);
        $this->assertGradeIntact($classification);
    }

    // ------------------------------------------- 3. o que não pode mexer

    #[Test]
    public function a_scale_a_school_authored_is_not_normalised(): void
    {
        $custom = $this->customScaleWithLevels();

        $this->seed(ReferenceDataSeeder::class);

        // Its name deliberately collides with the system scale's. Ownership is
        // organization_id, never the name: a school may call its scale whatever
        // it likes, including exactly what the system calls its own.
        $this->assertCustomScaleUntouched($custom);
    }

    #[Test]
    public function ownership_is_read_from_the_organization_and_not_from_the_name(): void
    {
        $source = (string) file_get_contents(database_path('seeders/SystemScalesSeeder.php'));

        // The canonical key, on the same columns the database itself makes
        // unique: scales(organization_id, name) and scale_levels(scale_id, code).
        $this->assertStringContainsString("['organization_id' => null, 'name' =>", $source);
        $this->assertStringContainsString("['code' => \$level['code']]", $source);

        $custom = $this->customScaleWithLevels();

        $this->assertNotNull($custom->organization_id);
        $this->assertTrue($this->systemScale('Escala 1 a 5')->isSystem());
    }

    #[Test]
    public function a_recorded_grade_keeps_pointing_at_the_level_it_pointed_at(): void
    {
        $classification = $this->aConfirmedGrade();

        $this->seed(ReferenceDataSeeder::class);

        // The old seeder deleted every level and wrote them again. Against a
        // restrictOnDelete foreign key that either aborts the seeder or, without
        // one, takes the row this grade is built on.
        $this->assertGradeIntact($classification);
    }

    #[Test]
    public function levels_found_on_a_numeric_system_scale_are_left_alone(): void
    {
        // A numeric scale CAN carry bands — §10.4 allows it and
        // ScaleProposalResolver resolves them. This seeder defines none for
        // «Escala 0 a 20», which is not the same as being entitled to remove
        // any it finds.
        $scale = $this->systemScale('Escala 0 a 20');
        $level = $scale->levels()->create([
            'code' => 'BOM', 'label' => 'Banda configurada pela escola', 'sequence' => 1,
            'band_min_normalized' => '50.000000', 'band_max_normalized' => '100.000000',
        ]);

        $this->seed(ReferenceDataSeeder::class);

        $survivor = ScaleLevel::find($level->id);

        $this->assertNotNull($survivor, 'o seeder não pode apagar níveis que não criou');
        $this->assertSame('Banda configurada pela escola', $survivor->label);
        // And §10: a numeric scale gets no INOVAR code.
        $this->assertNull($survivor->inovar_code);
    }

    // -------------------------------------------------- 4. o mapeamento

    #[Test]
    public function the_mapping_survives_a_renamed_label(): void
    {
        $level = $this->systemScale('Escala 1 a 5')->levels()->where('code', '4')->firstOrFail();
        $id = $level->id;

        $level->update(['label' => 'Desempenho Bom', 'inovar_code' => null]);

        $this->seed(ReferenceDataSeeder::class);

        $again = ScaleLevel::findOrFail($id);

        // Keyed on `code`, which is the band's stable identity, not on the words
        // shown to a teacher. The seeder also restores the reference label — the
        // system scale's wording is reference data, and it has been corrected
        // before («Muito Insuficiente» → «Fraco»).
        $this->assertSame('B', $again->inovar_code);
        $this->assertSame($id, $again->id);
    }

    #[Test]
    public function only_the_qualitative_system_scale_gets_codes(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        foreach (Scale::withoutGlobalScope('scaleVisibility')->whereNull('organization_id')->get() as $scale) {
            $codes = $scale->levels()->pluck('inovar_code')->filter()->all();

            if ($scale->name === 'Escala 1 a 5') {
                $this->assertSame(['F', 'I', 'S', 'B', 'MB'], $codes);

                continue;
            }

            // 0–20, percentagem, and anything numeric: no correspondence exists,
            // and inventing one would write a mention nobody assigned.
            $this->assertSame([], $codes, "«{$scale->name}» não pode receber códigos INOVAR");
        }
    }

    // ------------------------------------------------- 5. idempotência

    #[Test]
    public function running_it_three_times_changes_nothing_after_the_first(): void
    {
        $custom = $this->customScaleWithLevels();
        $classification = $this->aConfirmedGrade();

        $this->seed(ReferenceDataSeeder::class);
        $afterFirst = $this->wholeState();

        $this->seed(ReferenceDataSeeder::class);
        $this->assertSame($afterFirst, $this->wholeState(), 'a segunda execução alterou dados');

        $this->seed(ReferenceDataSeeder::class);
        $this->assertSame($afterFirst, $this->wholeState(), 'a terceira execução alterou dados');

        // No row was added on the way, either — same counts as after run one.
        $this->assertCount(5, $this->systemScale('Escala 1 a 5')->levels);
        $this->assertCustomScaleUntouched($custom);
        $this->assertGradeIntact($classification);
    }

    #[Test]
    public function the_full_reference_seeder_stays_green_over_a_populated_database(): void
    {
        $this->customScaleWithLevels();
        $this->aConfirmedGrade();
        $this->systemScale('Escala 0 a 20')->levels()->create([
            'code' => 'BOM', 'label' => 'Banda configurada pela escola', 'sequence' => 1,
        ]);

        // Not just the scales: EntitlementsSeeder and InstrumentTypesSeeder run
        // either side of it, and a seeder that aborts halfway leaves an
        // installation without the ones that come after. A delete refused by a
        // foreign key is exactly how it would abort.
        $this->seed(ReferenceDataSeeder::class);

        $this->assertGreaterThan(0, DB::table('modules')->count());
        $this->assertGreaterThan(0, DB::table('plans')->count());
        $this->assertGreaterThan(0, DB::table('instrument_types')->count());
    }

    // -------------------------------------------------------- helpers

    private function systemScale(string $name): Scale
    {
        return Scale::withoutGlobalScope('scaleVisibility')
            ->whereNull('organization_id')
            ->where('name', $name)
            ->firstOrFail();
    }

    /**
     * @return array<string, string|null>
     */
    private function codesOf(string $name): array
    {
        return $this->systemScale($name)->levels()->orderBy('sequence')
            ->pluck('inovar_code', 'code')->all();
    }

    private function customScale(): Scale
    {
        $organization = User::factory()->create()->personalOrganization();

        return app(CurrentOrganization::class)->runFor($organization, fn (): Scale => Scale::create([
            // Named exactly like the system's, on purpose.
            'name' => 'Escala 1 a 5', 'kind' => 'custom', 'min_value' => '1', 'max_value' => '5',
        ]));
    }

    /**
     * A scale a school authored: its own codes, its own words, its own bands —
     * every one of them different from the reference data's.
     */
    private function customScaleWithLevels(): Scale
    {
        $scale = $this->customScale();

        $scale->levels()->createMany([
            ['code' => '1', 'label' => 'Muito Insuficiente', 'sequence' => 1, 'numeric_value' => 1, 'is_negative' => true, 'band_min_normalized' => '0.000000', 'band_max_normalized' => '29.999999'],
            ['code' => '4', 'label' => 'Bom', 'sequence' => 2, 'numeric_value' => 4, 'is_negative' => false, 'band_min_normalized' => '30.000000', 'band_max_normalized' => '100.000000'],
            ['code' => 'EXC', 'label' => 'Excelente', 'sequence' => 3, 'numeric_value' => 5, 'is_negative' => false, 'inovar_code' => 'MB'],
        ]);

        return $scale;
    }

    private function assertCustomScaleUntouched(Scale $scale): void
    {
        $expected = $this->snapshotOf($scale);

        // Codes «1» and «4» collide with the reference data's, the labels and
        // bands disagree with it, and one level carries an INOVAR code the
        // school chose. None of it may be corrected towards the system's.
        $this->assertSame($expected, $this->snapshotOf($scale->fresh()));

        $levels = $scale->fresh()->levels()->orderBy('sequence')->get();

        $this->assertCount(3, $levels);
        $this->assertSame('Muito Insuficiente', $levels[0]->label);
        $this->assertSame('29.999999', $levels[0]->band_max_normalized);
        $this->assertNull($levels[0]->inovar_code);
        $this->assertSame('MB', $levels[2]->inovar_code);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotOf(Scale $scale): array
    {
        return [
            'scale' => (array) DB::table('scales')->where('id', $scale->id)->first(),
            'levels' => DB::table('scale_levels')->where('scale_id', $scale->id)
                ->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
        ];
    }

    /**
     * A grade a teacher actually confirmed, whose final_scale_level_id points at
     * a level of the system scale.
     */
    private function aConfirmedGrade(): Classification
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        return app(CurrentOrganization::class)->runFor(
            $teacher->personalOrganization(),
            function () use ($teacher): Classification {
                $class = SchoolClass::where('label', '7.º A')->firstOrFail();
                $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();

                app(ProposeClassifications::class)->forPeriod($class, $period);

                $classification = Classification::whereNotNull('proposed_scale_level_id')->firstOrFail();
                app(ConfirmClassification::class)->confirm($classification, $teacher);

                $classification->refresh();

                $this->assertNotNull($classification->final_scale_level_id);

                return $classification;
            },
        );
    }

    private function assertGradeIntact(Classification $classification): void
    {
        $again = Classification::withoutGlobalScopes()->find($classification->id);

        $this->assertNotNull($again, 'a classificação desapareceu');
        $this->assertSame($classification->final_scale_level_id, $again->final_scale_level_id);
        $this->assertSame($classification->proposed_scale_level_id, $again->proposed_scale_level_id);
        $this->assertSame($classification->final_value, $again->final_value);

        // The row it points at is still there, still the same row.
        $level = ScaleLevel::find($again->final_scale_level_id);

        $this->assertNotNull($level, 'o nível referenciado foi apagado — a chave estrangeira ficou pendurada');
        $this->assertSame($again->final_scale_level_id, $level->id);
    }

    /**
     * The reference bands go back to having no INOVAR code: the state of this
     * table on an installation that has just run the migration and not yet the
     * seeder.
     *
     * Only the system's. A code on a school's own scale is left standing on
     * purpose — it models a school that set one for itself, and the seeder has
     * no business correcting it either.
     */
    private function pretendTheColumnIsNew(): void
    {
        DB::table('scale_levels')
            ->whereIn('scale_id', DB::table('scales')->whereNull('organization_id')->pluck('id'))
            ->update(['inovar_code' => null]);
    }

    /**
     * @return list<string>
     */
    private function levelIdentifiers(): array
    {
        return DB::table('scale_levels')->orderBy('id')
            ->get(['id', 'scale_id', 'code'])
            ->map(fn ($row): string => "{$row->id}:{$row->scale_id}:{$row->code}")
            ->all();
    }

    /**
     * Every scale, every level and every classification, whole.
     *
     * Compared as raw rows rather than through the models, so a change to a
     * column nobody thought to assert on still fails the test.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function wholeState(): array
    {
        $rows = fn (string $table): array => DB::table($table)->orderBy('id')
            ->get()->map(fn ($row): array => (array) $row)->all();

        return [
            'scales' => $rows('scales'),
            'scale_levels' => $rows('scale_levels'),
            'classifications' => $rows('classifications'),
            'organizations' => Organization::query()->orderBy('id')->pluck('id')->all(),
        ];
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $state
     * @return array<string, list<array<string, mixed>>>
     */
    private function withoutInovarCodes(array $state): array
    {
        $state['scale_levels'] = array_map(function (array $row): array {
            unset($row['inovar_code'], $row['updated_at']);

            return $row;
        }, $state['scale_levels']);

        return $state;
    }

    /**
     * The migration's code with its docblock removed — the prose explains why
     * the mapping is NOT keyed on the label, and would otherwise trip the
     * assertions that no such keying exists.
     */
    private function phpOf(string $source): string
    {
        return (string) preg_replace('#/\*\*.*?\*/#s', '', $source);
    }
}
