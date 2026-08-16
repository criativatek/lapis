<?php

namespace Tests\Feature\Export;

use App\Models\Domain;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\Assessment\BuildResultsProgression;
use App\Services\Export\InovarExportPreviewBuilder;
use App\Services\Export\InovarTemplateReader;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InovarGridFixture;
use Tests\TestCase;

/**
 * What would be written, and everything standing in the way of writing it.
 *
 * The teacher sees this before a single cell is filled, which is the point: a
 * grid that came back subtly wrong would be uploaded to INOVAR, and nobody
 * would find out until the marks were.
 */
class InovarExportPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->teacher->personalOrganization(), $callback);
    }

    /**
     * Gives the class's students invented process numbers, through the domain
     * rather than by hand — the same field the roster import writes.
     *
     * @return array<string, string> student name → process number
     */
    private function giveProcessNumbers(): array
    {
        return $this->asTenant(function (): array {
            $assigned = [];
            $next = 1234;

            foreach (SchoolClass::where('label', '7.º A')->firstOrFail()
                ->enrollments()->with('student.identity')->orderBy('class_number')->get() as $enrollment) {
                $number = str_pad((string) $next++, 6, '0', STR_PAD_LEFT);
                $enrollment->student->identity->update(['school_number' => $number]);
                $assigned[$enrollment->student->identity->display_name] = $number;
            }

            return $assigned;
        });
    }

    /**
     * A grid naming this class's own domains and its own students.
     *
     * @param  array<string, mixed>  $options
     */
    private function gridFor(array $numbers, array $options = []): string
    {
        $domains = $this->asTenant(function (): array {
            $version = SchoolClass::where('label', '7.º A')->firstOrFail()->profileVersion;
            $names = Domain::whereIn('id', $version->domains()->pluck('domain_id'))->orderBy('name')->pluck('name')->all();

            $columns = [];

            foreach (array_slice($names, 0, 3) as $index => $name) {
                $columns[chr(ord('D') + $index)] = $name;
            }

            return $columns;
        });

        // The grid lists this class's own students, by the numbers they were
        // given — unless the caller wants a different roll on purpose.
        $students = $options['students'] ?? ($numbers === [] ? InovarGridFixture::STUDENTS : array_flip($numbers));

        return (new InovarGridFixture)->build([
            ...$options,
            'domains' => $options['domains'] ?? $domains,
            'students' => $students,
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function preview(string $path): array
    {
        return $this->asTenant(function () use ($path): array {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            $template = app(InovarTemplateReader::class)->read($path);

            return app(InovarExportPreviewBuilder::class)->build($class, $period, $template);
        });
    }

    // ------------------------------------------------- 1. o caminho feliz

    #[Test]
    public function every_student_and_every_domain_is_matched_and_ready(): void
    {
        $numbers = $this->giveProcessNumbers();
        $preview = $this->preview($this->gridFor($numbers));

        $this->assertSame([], $preview['summary']['blocking_errors']);
        $this->assertSame(6, $preview['summary']['matched_students']);
        $this->assertSame(3, $preview['summary']['mapped_domains']);
        $this->assertSame(0, $preview['summary']['unmapped_domains']);
        $this->assertGreaterThan(0, $preview['summary']['ready_cells']);

        // Every writable cell carries one of the five codes INOVAR accepts, and
        // the band it came from.
        foreach ($preview['values'] as $value) {
            if ($value['writable']) {
                $this->assertContains($value['inovar_code'], ['F', 'I', 'S', 'B', 'MB']);
                $this->assertNotNull($value['qualitative_band']);
            }
        }
    }

    #[Test]
    public function a_process_number_with_leading_zeros_matches_as_written(): void
    {
        $numbers = $this->giveProcessNumbers();
        $preview = $this->preview($this->gridFor($numbers));

        $matched = array_values(array_filter($preview['students'], fn (array $row): bool => $row['matched']));

        $this->assertNotEmpty($matched);
        // «001234» is not 1234: the zeros are part of somebody's identifier.
        $this->assertSame('001234', $matched[0]['process_number']);
    }

    // ------------------------------------------------ 2. o que bloqueia

    #[Test]
    public function a_class_whose_students_have_no_process_number_is_told_exactly_that(): void
    {
        // The demo class starts with none, which is what a hand-typed class
        // always looks like.
        $preview = $this->preview($this->gridFor([]));

        $this->assertContains(
            'Existem alunos sem N.º de processo. Complete esta informação para poder exportar para o INOVAR.',
            $preview['summary']['blocking_errors'],
        );
    }

    #[Test]
    public function a_process_number_repeated_in_the_grid_blocks_the_export(): void
    {
        $numbers = $this->giveProcessNumbers();
        $first = array_values($numbers)[0];

        // Two lines claiming the same student: which one gets the marks is not
        // something to decide by guessing.
        $path = (new InovarGridFixture)->build([
            'domains' => ['D' => 'Leitura', 'E' => 'Escrita'],
            'students' => [$first => 'Primeira Linha', $first.' ' => 'Segunda Linha'],
        ]);

        $preview = $this->preview($path);

        $this->assertNotEmpty($preview['summary']['blocking_errors']);
        $this->assertStringContainsString('aparece mais do que uma vez', implode(' ', $preview['summary']['blocking_errors']));
    }

    #[Test]
    public function a_column_that_matches_no_domain_of_the_profile_blocks_the_export(): void
    {
        $numbers = $this->giveProcessNumbers();

        $preview = $this->preview($this->gridFor($numbers, [
            'domains' => ['D' => 'Leitura', 'E' => 'Cidadania e Desenvolvimento'],
        ]));

        // Never guessed at: a column filled with another domain's marks is the
        // failure this whole flow exists to avoid.
        $this->assertStringContainsString(
            'não corresponde a nenhum domínio',
            implode(' ', $preview['summary']['blocking_errors']),
        );
        $this->assertSame(1, $preview['summary']['unmapped_domains']);
    }

    #[Test]
    public function a_line_of_the_grid_that_is_not_in_this_class_is_reported(): void
    {
        $this->giveProcessNumbers();

        $preview = $this->preview($this->gridFor([], [
            'domains' => ['D' => 'Leitura', 'E' => 'Escrita'],
            'students' => ['999999' => 'Alguém De Outra Turma', '999998' => 'Outro Qualquer'],
        ]));

        $this->assertStringContainsString(
            'Não há nesta turma nenhum aluno com este N.º de processo',
            implode(' ', $preview['summary']['blocking_errors']),
        );
    }

    #[Test]
    public function a_scale_with_no_inovar_correspondence_blocks_the_export(): void
    {
        $numbers = $this->giveProcessNumbers();

        $this->asTenant(function (): void {
            Scale::where('name', 'Escala 1 a 5')->firstOrFail()
                ->levels()->where('code', '3')->update(['inovar_code' => null]);
        });

        $preview = $this->preview($this->gridFor($numbers));

        $this->assertStringContainsString(
            'não tem correspondência INOVAR configurada para todas as menções',
            implode(' ', $preview['summary']['blocking_errors']),
        );
    }

    // ----------------------------------------------- 3. o que só avisa

    #[Test]
    public function a_domain_with_no_mention_leaves_its_cell_alone_and_says_so(): void
    {
        $numbers = $this->giveProcessNumbers();
        $preview = $this->preview($this->gridFor($numbers));

        $blank = array_values(array_filter($preview['values'], fn (array $row): bool => ! $row['writable']));

        foreach ($blank as $value) {
            // Never an F, never a zero: no evidence is not a low mark (§10).
            $this->assertNull($value['inovar_code']);
        }

        if ($blank !== []) {
            $this->assertStringContainsString('nunca são preenchidas com Fraco', implode(' ', $preview['summary']['warnings']));
        }

        // …and none of this blocks anything.
        $this->assertSame([], $preview['summary']['blocking_errors']);
    }

    #[Test]
    public function partial_coverage_warns_and_does_not_block(): void
    {
        $numbers = $this->giveProcessNumbers();
        $preview = $this->preview($this->gridFor($numbers));

        // The warning is about marks that WILL be written from partial
        // evidence. A cell with no mention is not written at all, so it is the
        // other warning's business.
        $partial = array_filter(
            $preview['values'],
            fn (array $row): bool => $row['writable'] && $row['coverage_warning'],
        );

        $this->assertSame([], $preview['summary']['blocking_errors']);

        if ($partial !== []) {
            $this->assertStringContainsString('cobertura parcial', implode(' ', $preview['summary']['warnings']));
        }
    }

    // ------------------------------------- 4. de onde vêm os resultados

    #[Test]
    public function the_mentions_are_the_ones_the_quadro_sintese_shows(): void
    {
        $numbers = $this->giveProcessNumbers();
        $preview = $this->preview($this->gridFor($numbers));

        $fromScreen = $this->asTenant(function (): array {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $progression = app(BuildResultsProgression::class)->for($class);
            $byStudentDomain = [];

            foreach ($progression['students'] as $student) {
                foreach ($student['periods'][0]['domains'] as $domain) {
                    $byStudentDomain[$student['name']][$domain['domain_id']] = $domain['mention']['label'] ?? null;
                }
            }

            return $byStudentDomain;
        });

        $domainIds = $this->asTenant(fn (): array => Domain::pluck('id', 'name')->all());

        // Nothing was recalculated on the way to INOVAR.
        foreach ($preview['values'] as $value) {
            $expected = $fromScreen[$value['student']][$domainIds[$value['domain']]] ?? null;
            $this->assertSame($expected, $value['qualitative_band']);
        }
    }

    #[Test]
    public function the_builder_never_computes_a_result_of_its_own(): void
    {
        $source = (string) file_get_contents(app_path('Services/Export/InovarExportPreviewBuilder.php'));

        $this->assertStringContainsString('BuildResultsProgression', $source);
        // No arithmetic, no band matching, no engine.
        $this->assertStringNotContainsString('band_min_normalized', $source);
        $this->assertStringNotContainsString('CalculationEngine', $source);
        $this->assertStringNotContainsString('Bc::', $source);
    }

    #[Test]
    public function matching_never_falls_back_to_the_name(): void
    {
        $numbers = $this->giveProcessNumbers();
        $names = array_keys($numbers);

        // Right names, wrong numbers: nothing matches, because the name is
        // never what decides.
        $preview = $this->preview($this->gridFor([], [
            'domains' => ['D' => 'Leitura', 'E' => 'Escrita'],
            'students' => ['700001' => $names[0], '700002' => $names[1]],
        ]));

        $this->assertSame(0, $preview['summary']['matched_students']);
        $this->assertSame(2, $preview['summary']['unmatched_students']);
    }
}
