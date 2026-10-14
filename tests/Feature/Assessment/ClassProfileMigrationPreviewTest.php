<?php

namespace Tests\Feature\Assessment;

use App\Models\AssessmentProfileVersion;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\ActivateProfileVersion;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\MigrateClassProfile;
use App\Services\Assessment\ProfileBuilder;
use App\Services\Assessment\ProposeClassifications;
use App\Services\Assessment\ScaleProposalResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MigratesPercentageClassToLevels;
use Tests\TestCase;

/**
 * The migration preview explains the change instead of comparing only the
 * normalized percentages.
 *
 * The production case showed «0 alunos afetados» for a class whose proposals
 * were about to stop being read as percentages and start being read as levels of
 * a 1–5: the percentage underneath does not change, but everything the teacher
 * SEES does. The preview now names both scales, both sets of domains and
 * weights, and writes each cell as the teacher will read it before and after.
 */
class ClassProfileMigrationPreviewTest extends TestCase
{
    use MigratesPercentageClassToLevels, RefreshDatabase;

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTeacherAndDemo();
    }

    /**
     * @return array<string, mixed>
     */
    private function previewOf(SchoolClass $class, AssessmentProfileVersion $to): array
    {
        return $this->inTenant(fn (): array => app(MigrateClassProfile::class)->preview(
            SchoolClass::whereKey($class->id)->firstOrFail(),
            $to,
        ));
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    private function row(array $preview, string $name): array
    {
        $row = collect($preview['rows'])->firstWhere('name', $name);
        $this->assertNotNull($row, "linha de {$name}");

        return $row;
    }

    #[Test]
    public function a_change_of_scale_is_named_and_everything_else_is_reported_as_the_same(): void
    {
        $scenario = $this->percentageClassWithProposals('65');

        $preview = $this->previewOf($scenario['class'], $this->levelVersion);

        $this->assertSame('Percentagem (0 a 100)', $preview['from']['scale']['name']);
        $this->assertSame('percentage', $preview['from']['scale']['kind']);
        $this->assertSame('Escala 1 a 5', $preview['to']['scale']['name']);
        $this->assertSame('level', $preview['to']['scale']['kind']);

        $this->assertTrue($preview['comparison']['scale_changes']);
        $this->assertTrue($preview['comparison']['domains_same']);
        $this->assertTrue($preview['comparison']['weights_same']);
        $this->assertTrue($preview['comparison']['rules_same']);

        // Os domínios, por ordem, com o peso de cada versão.
        $this->assertSame($preview['from']['domains'], $preview['to']['domains']);
        $this->assertNotEmpty($preview['to']['domains']);
        $this->assertSame(
            array_column($preview['to']['domains'], 'name'),
            array_column($preview['comparison']['domains'], 'name'),
        );

        foreach ($preview['comparison']['domains'] as $domain) {
            $this->assertNotNull($domain['from_weight']);
            $this->assertSame($domain['from_weight'], $domain['to_weight']);
            $this->assertFalse($domain['changed']);
        }
    }

    #[Test]
    public function the_cells_are_read_on_the_scale_of_each_side_and_every_student_with_a_proposal_is_affected(): void
    {
        $scenario = $this->percentageClassWithProposals('65');
        $preview = $this->previewOf($scenario['class'], $this->levelVersion);

        $levelScale = $this->levelVersion->scale()->with('levels')->firstOrFail();
        $resolver = app(ScaleProposalResolver::class);

        $withProposal = 0;

        foreach ($preview['rows'] as $row) {
            $cell = $row['cells'][0];

            if ($cell['state'] !== 'refreshed') {
                continue;
            }

            $withProposal++;

            // Antes: a percentagem, tal como a turma a lê hoje.
            $this->assertMatchesRegularExpression('/^\d+(\.\d+)?%$/', $cell['before_text']);

            // Depois: o nível da banda em que esse mesmo resultado cai. A
            // percentagem em si não muda — só a escala em que se lê.
            $band = $resolver->bandFor($levelScale, $cell['after']);
            $this->assertNotNull($band);
            $this->assertSame("{$band->code} — {$band->label}", $this->normalised($cell['after_text']));
            $this->assertMatchesRegularExpression('/^\d — \S/u', $cell['after_text']);

            $this->assertTrue($cell['changed']);
            $this->assertTrue($row['changed']);
        }

        $this->assertGreaterThan(0, $withProposal);
        $this->assertSame($withProposal, $preview['affected_enrollment_count']);
        $this->assertSame($withProposal, $preview['recalculated_result_count']);
    }

    #[Test]
    public function a_kept_decision_is_shown_on_the_scale_it_was_taken_on(): void
    {
        $scenario = $this->percentageClassWithProposals('65');
        $preview = $this->previewOf($scenario['class'], $this->levelVersion);

        $cell = $this->row($preview, 'Carolina Nunes')['cells'][0];

        $this->assertSame('kept', $cell['state']);
        $this->assertSame('65%', $cell['before_text']);
        $this->assertSame('65%', $cell['after_text']);
        $this->assertFalse($cell['changed']);
        $this->assertSame(1, $preview['kept_result_count']);

        // O valor em bruto continua a viajar como sempre viajou.
        $this->assertSame('65.000', $cell['before']);
        $this->assertSame('65.000', $cell['after']);
    }

    #[Test]
    public function a_kept_decision_on_a_level_is_shown_as_that_level(): void
    {
        $this->inTenant(function (): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();

            app(ProposeClassifications::class)->forPeriod($class, $period);
            $level = $class->profileVersion->scale->levels()->where('code', '3')->firstOrFail();
            app(ConfirmClassification::class)->confirm($this->classificationOf('Carolina Nunes', $period->id), $this->teacher, $level->id);
        });

        $class = $this->demoClass();
        $this->inTenant(fn () => $this->levelVersion = $this->activateVersionOn($class, 'Escala 1 a 5'));

        $preview = $this->previewOf($class, $this->levelVersion);
        $cell = $this->row($preview, 'Carolina Nunes')['cells'][0];

        $this->assertSame('kept', $cell['state']);
        $this->assertMatchesRegularExpression('/^3 — \S/u', $cell['before_text']);
        $this->assertSame($cell['before_text'], $cell['after_text']);
    }

    // ===================================== as outras contas não mudam

    #[Test]
    public function a_change_of_weights_on_the_same_scale_is_still_marked_by_the_normalized_values(): void
    {
        $changed = $this->inTenant(function (): array {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            $profile = $class->profileVersion->profile;

            app(ProposeClassifications::class)->forPeriod($class, $period);

            app(ProfileBuilder::class)->update(
                $profile,
                ['subject_id' => $profile->subject_id, 'name' => $profile->name, 'description' => $profile->description],
                $class->profileVersion->scale_id,
                [
                    ['name' => 'Oralidade', 'weight' => 10],
                    ['name' => 'Leitura', 'weight' => 40],
                    ['name' => 'Escrita', 'weight' => 30],
                    ['name' => 'Gramática', 'weight' => 10],
                    ['name' => 'Educação Literária', 'weight' => 10],
                ],
                $profile->gradeLevels->pluck('grade_level')->all(),
            );

            $version = app(ActivateProfileVersion::class)
                ->activate($profile->refresh()->draftVersion(), $this->teacher);

            return ['class' => $class, 'version' => $version];
        });

        $preview = $this->previewOf($changed['class'], $changed['version']);

        $this->assertFalse($preview['comparison']['scale_changes']);
        $this->assertTrue($preview['comparison']['domains_same']);
        $this->assertFalse($preview['comparison']['weights_same']);
        $this->assertSame($preview['from']['scale'], $preview['to']['scale']);

        // O que sempre marcou `changed` continua a marcar: o normalizado.
        $this->assertGreaterThan(0, $preview['affected_enrollment_count']);

        foreach ($preview['rows'] as $row) {
            foreach ($row['cells'] as $cell) {
                if ($cell['state'] !== 'refreshed') {
                    continue;
                }

                $sameNormalized = bccomp((string) $cell['before'], (string) $cell['after'], 6) === 0;
                $sameReading = $cell['before_text'] === $cell['after_text'];

                // Igual a hoje: mudou se o normalizado mudou; se só o texto
                // mudasse sem o normalizado, também — mas na mesma escala o
                // texto não muda sozinho.
                $this->assertSame(! ($sameNormalized && $sameReading), $cell['changed']);

                if ($sameNormalized) {
                    $this->assertTrue($sameReading);
                }
            }
        }
    }

    #[Test]
    public function an_identical_proposal_on_the_same_scale_is_not_reported_as_a_change(): void
    {
        // Duas versões iguais em tudo menos no número: nada que o professor leia
        // muda, logo ninguém é «afetado».
        $scenario = $this->inTenant(function (): array {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            app(ProposeClassifications::class)->forPeriod($class, $period);

            return ['class' => $class, 'version' => $this->activateVersionOn($class, 'Escala 1 a 5')];
        });

        $preview = $this->previewOf($scenario['class'], $scenario['version']);

        $this->assertFalse($preview['comparison']['scale_changes']);
        $this->assertTrue($preview['comparison']['weights_same']);
        $this->assertTrue($preview['comparison']['rules_same']);
        $this->assertSame(0, $preview['affected_enrollment_count']);
        $this->assertGreaterThan(0, $preview['recalculated_result_count']);
        $this->assertSame(0, $preview['kept_result_count']);
    }

    #[Test]
    public function migrating_stores_the_same_explained_preview_and_counts_it_once(): void
    {
        $scenario = $this->percentageClassWithProposals('65');
        $classification = $this->inTenant(fn (): Classification => $this->classificationOf('Carolina Nunes', $scenario['period']->id));
        $published = $classification->getAttributes();

        $migration = $this->inTenant(fn () => app(MigrateClassProfile::class)->migrate(
            SchoolClass::whereKey($scenario['class']->id)->firstOrFail(),
            $this->levelVersion,
            'Passa a 1 a 5.',
            $this->teacher,
        ));

        $stored = $migration->impact_preview;

        $this->assertTrue($stored['comparison']['scale_changes']);
        $this->assertSame(1, $stored['kept_result_count']);
        $this->assertSame($stored['affected_enrollment_count'], $migration->affected_enrollment_count);
        $this->assertGreaterThan(0, $migration->affected_enrollment_count);

        // O que a migração deixa atrás de si é a decisão, tal como estava.
        $after = $this->inTenant(fn (): Classification => Classification::whereKey($classification->id)->firstOrFail());
        $this->assertEquals($published, $after->getAttributes());
        $this->assertSame(ClassificationScope::Period, $after->scope);
    }

    /**
     * A thin space or a non-breaking one in a label would be a formatting
     * accident, not a different text.
     */
    private function normalised(string $text): string
    {
        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }
}
