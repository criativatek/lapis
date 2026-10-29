<?php

namespace Tests\Feature\Import;

use App\Domain\Import\Correction\CorrectionGridSource;
use App\Domain\Import\Correction\ImportMapping;
use App\Models\CorrectionImport;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentItem;
use App\Models\InstrumentType;
use App\Models\ItemDomainAllocation;
use App\Models\Scale;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\StudentItemScore;
use App\Services\Assessment\ActivateProfileVersion;
use App\Services\Assessment\Analysis\BuildResultsAnalysis;
use App\Services\Assessment\ProfileBuilder;
use App\Services\Assessment\RecordScores;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Import\IntuitivoWorkbookBuilder;

/**
 * A turma de 23, um ficheiro com 21: dois alunos fizeram a prova em papel.
 *
 * A grelha pertence à turma e ao instrumento, não ao ficheiro. Importar o
 * Intuitivo escreve só o que o ficheiro julgou; os dois alunos que ele não traz
 * continuam na grelha, sem nenhuma linha em `student_item_scores` — nem zero,
 * nem falta — e o professor preenche-os à mão no MESMO instrumento, sem tocar
 * nas notas importadas.
 *
 * O ficheiro vem de propósito por uma ordem diferente da turma, e os números de
 * turma foram criados embaralhados: nada aqui pode depender da posição.
 */
class IntuitivoPartialRosterTest extends CorrectionImportHttpTest
{
    protected const MESSAGE_21_2 = '21 alunos com resultados para importar. 2 alunos da turma sem resultados neste ficheiro. Poderá preencher as respetivas classificações na grelha de correção.';

    /** Números de turma, pela ordem em que as 20 matrículas novas são criadas. */
    protected const SCRAMBLED_NUMBERS = [17, 5, 22, 9, 4, 13, 20, 6, 15, 11, 23, 8, 19, 7, 14, 10, 21, 12, 16, 18];

    /** Quem fez a prova em papel: os números de turma 7 e 19. */
    protected const PAPER_NUMBERS = [7, 19];

    /** @var array<int, string> número de turma => nome */
    protected array $names = [];

    /** @var array<int, int> número de turma => id da matrícula */
    protected array $enrollmentByNumber = [];

    /** @var array<string, int> nome do domínio => id */
    protected array $domains = [];

    protected string $workbook;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workbook = sys_get_temp_dir().'/lapis-intuitivo-parcial-'.bin2hex(random_bytes(6)).'.xlsx';

        $surnames = ['Exemplo', 'Teste', 'Fictício', 'Inventado', 'Suposto', 'Imaginário', 'Simulado', 'Fabricado'];
        $given = ['Ana', 'Bruno', 'Carla', 'Diogo', 'Elsa', 'Filipe', 'Gisela', 'Hugo', 'Inês', 'Jorge', 'Karina', 'Luís'];

        foreach (range(1, 23) as $number) {
            $this->names[$number] = $given[($number - 1) % 12].' '.$surnames[intdiv($number - 1, 3)].' '.chr(64 + $number);
        }

        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            // As três matrículas que a turma-base já tem (números 1, 2 e 3).
            foreach (Enrollment::where('class_id', $this->class->id)->orderBy('class_number')->get() as $enrollment) {
                $this->nameEnrollment($enrollment, $this->names[$enrollment->class_number]);
                $this->enrollmentByNumber[$enrollment->class_number] = (int) $enrollment->getKey();
            }

            // As outras vinte, com os números fora de ordem.
            foreach (self::SCRAMBLED_NUMBERS as $number) {
                $student = Student::factory()->recycle($this->organization)->create();

                $enrollment = Enrollment::factory()->recycle($this->organization)->create([
                    'class_id' => $this->class->id,
                    'student_id' => $student->getKey(),
                    'class_number' => $number,
                    'enrolled_on' => now()->subMonths(6)->toDateString(),
                ]);

                $this->nameEnrollment($enrollment, $this->names[$number]);
                $this->enrollmentByNumber[$number] = (int) $enrollment->getKey();
            }

            // Um perfil de avaliação ativo, com os três domínios: sem ele não há
            // totais por domínio nem classificação global para comparar.
            $profile = app(ProfileBuilder::class)->create(
                ['academic_year_id' => $this->class->academic_year_id, 'subject_id' => $this->class->subject_id, 'name' => 'Português', 'description' => 'Cenário.'],
                Scale::where('name', 'Escala 1 a 5')->firstOrFail()->id,
                [['name' => 'Leitura', 'weight' => 40], ['name' => 'Gramática', 'weight' => 30], ['name' => 'Escrita', 'weight' => 30]],
                ['7.º'],
            );
            $version = app(ActivateProfileVersion::class)->activate($profile->draftVersion(), $this->teacher);
            $this->class->forceFill(['assessment_profile_version_id' => $version->id])->save();

            foreach (['Leitura', 'Gramática', 'Escrita'] as $name) {
                $this->domains[$name] = (int) Domain::where('name', $name)->firstOrFail()->getKey();
            }
        });

        $this->assertCount(23, $this->enrollmentByNumber);
    }

    protected function tearDown(): void
    {
        @unlink($this->workbook);
        parent::tearDown();
    }

    protected function nameEnrollment(Enrollment $enrollment, string $name): void
    {
        StudentIdentity::create([
            'student_id' => $enrollment->student_id,
            'organization_id' => $this->organization->getKey(),
            'display_name' => $name,
        ]);
    }

    // ------------------------------------------------------------- the file

    /**
     * Três secções, dezasseis questões: I (6 × 2), II (5 × 4), III (5 × 3).
     * Cada questão é cotada pelos seus próprios máximos.
     *
     * @return list<int|float>
     */
    protected function maxima(): array
    {
        return [2, 2, 2, 2, 2, 2, 4, 4, 4, 4, 4, 3, 3, 3, 3, 3];
    }

    /**
     * A nota que o ficheiro dá a um aluno numa questão — função só do número de
     * turma e da questão, para o teste poder reconstruir o esperado.
     */
    protected function markFor(int $classNumber, int $question): int
    {
        $maximum = (int) $this->maxima()[$question];

        return ($classNumber * 3 + $question * 5) % ($maximum + 1);
    }

    /**
     * O ficheiro do Intuitivo: 21 alunos (todos menos os do papel), por uma
     * ordem que não é a da turma — a inversa.
     *
     * @param  array<int, int>  $overrides  "número:questão" => nota, para alterar uma célula
     */
    protected function workbookBuilder(array $overrides = []): IntuitivoWorkbookBuilder
    {
        $builder = IntuitivoWorkbookBuilder::make()
            ->group('GRUPO I', array_slice($this->maxima(), 0, 6))
            ->group('GRUPO II', array_slice($this->maxima(), 6, 5))
            ->group('GRUPO III', array_slice($this->maxima(), 11, 5));

        foreach (array_reverse(array_keys($this->names)) as $number) {
            if (in_array($number, self::PAPER_NUMBERS, true)) {
                continue;
            }

            $scores = [];

            foreach (array_keys($this->maxima()) as $question) {
                $scores[] = $overrides[$number.':'.$question] ?? $this->markFor($number, $question);
            }

            $builder->student($this->names[$number], $scores);
        }

        return $builder;
    }

    protected function uploadWorkbook(?IntuitivoWorkbookBuilder $builder = null): CorrectionImport
    {
        Storage::fake('local');

        ($builder ?? $this->workbookBuilder())->writeTo($this->workbook);

        $this->actingAs($this->teacher)->post('/imports/correction', [
            'class_id' => $this->class->id,
            'source' => CorrectionGridSource::Intuitivo->value,
            'file' => new UploadedFile($this->workbook, 'Teste de Português - 7.º A.xlsx', null, null, true),
        ])->assertRedirect();

        return app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn () => CorrectionImport::latest('id')->firstOrFail(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function preview(CorrectionImport $import): array
    {
        return $this->actingAs($this->teacher)
            ->get("/imports/correction/{$import->ulid}")
            ->assertOk()
            ->viewData('page')['props']['preview'];
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>|null
     */
    protected function missingIssue(array $preview): ?array
    {
        foreach ($preview['issues'] as $issue) {
            if ($issue['code'] === 'student_missing_from_source') {
                return $issue;
            }
        }

        return null;
    }

    /**
     * O que o assistente envia: o emparelhamento que a pré-visualização já fez,
     * cotações do ficheiro e um domínio por secção.
     *
     * @param  array<string, mixed>  $preview
     * @param  array<string, int|null>  $studentOverrides  decisões do professor sobre linhas concretas
     * @return array<string, mixed>
     */
    protected function createMapping(array $preview, array $studentOverrides = [], ?string $appliedOn = null): array
    {
        $students = [];

        foreach ($preview['students'] as $row) {
            if ($row['enrollment_id'] !== null) {
                $students[$row['source_key']] = $row['enrollment_id'];
            }
        }

        foreach ($studentOverrides as $sourceKey => $enrollmentId) {
            $students[$sourceKey] = $enrollmentId;
        }

        $byGroup = ['Leitura', 'Gramática', 'Escrita'];
        $points = [];
        $domains = [];

        foreach ($preview['items'] as $index => $item) {
            $points[$item['source_key']] = $item['points'];
            $domains[$item['source_key']] = [[
                'domain_id' => $this->domains[$byGroup[$index < 6 ? 0 : ($index < 11 ? 1 : 2)]],
                'allocation_percent' => '100',
            ]];
        }

        return [
            'mode' => ImportMapping::MODE_CREATE,
            'result_mode' => ImportMapping::RESULT_PER_QUESTION,
            'students' => $students,
            'points' => $points,
            'domains' => $domains,
            'instrument' => [
                'title' => 'Teste de Português — 7.º A',
                'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                'applied_on' => $appliedOn ?? now()->subDays(2)->toDateString(),
                'academic_period_id' => $this->period->id,
                'purpose' => 'summative',
                'counts_toward_classification' => true,
            ],
        ];
    }

    protected function confirmCreate(?CorrectionImport $import = null): Instrument
    {
        $import ??= $this->uploadWorkbook();
        $mapping = $this->createMapping($this->preview($import));

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);
        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertRedirect();

        return $this->instrument();
    }

    protected function instrument(): Instrument
    {
        return app(CurrentOrganization::class)->runFor($this->organization, fn () => Instrument::firstOrFail());
    }

    /**
     * @return list<int> ids das questões, pela ordem do instrumento
     */
    protected function itemIds(Instrument $instrument): array
    {
        return app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn () => InstrumentItem::where('instrument_id', $instrument->getKey())->orderBy('sequence')->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        );
    }

    /**
     * @return array<string, StudentItemScore> "matrícula:questão" => nota
     */
    protected function scoresOf(Instrument $instrument): array
    {
        return app(CurrentOrganization::class)->runFor($this->organization, function () use ($instrument): array {
            $scores = [];

            foreach (StudentItemScore::where('instrument_id', $instrument->getKey())->get() as $score) {
                $scores[$score->enrollment_id.':'.$score->instrument_item_id] = $score;
            }

            return $scores;
        });
    }

    // ------------------------------------------------------ a. the preview

    #[Test]
    public function the_preview_names_who_has_results_and_who_has_not(): void
    {
        $import = $this->uploadWorkbook();
        $preview = $this->preview($this->patched($import));

        $issue = $this->missingIssue($preview);

        $this->assertNotNull($issue);
        $this->assertSame('info', $issue['severity']);
        $this->assertSame(21, $issue['context']['with_results']);
        $this->assertSame(2, $issue['context']['missing']);
        $this->assertSame(self::MESSAGE_21_2, $issue['message']);

        // Nem «faltou», nem zero.
        $this->assertStringNotContainsStringIgnoringCase('faltou', $issue['message']);
        $this->assertStringNotContainsStringIgnoringCase('zero', $issue['message']);
    }

    #[Test]
    public function the_notice_is_there_before_a_date_is_known_and_the_whole_class_is_the_universe(): void
    {
        // Sem instrumento e sem data: o mesmo recurso do emparelhamento — a
        // turma toda (`all()`), nunca o universo de hoje.
        $import = $this->uploadWorkbook();

        $issue = $this->missingIssue($this->preview($import));

        $this->assertNotNull($issue);
        $this->assertSame(21, $issue['context']['with_results']);
        $this->assertSame(2, $issue['context']['missing']);
    }

    #[Test]
    public function the_notice_is_singular_when_there_is_one_student_on_either_side(): void
    {
        // Só um aluno no ficheiro e a turma de 23: 1 com resultados, 22 sem.
        $builder = IntuitivoWorkbookBuilder::make()
            ->group('GRUPO I', [2, 2])
            ->student($this->names[1], [1, 2]);

        $issue = $this->missingIssue($this->preview($this->uploadWorkbook($builder)));

        $this->assertSame(
            '1 aluno com resultados para importar. 22 alunos da turma sem resultados neste ficheiro. Poderá preencher as respetivas classificações na grelha de correção.',
            $issue['message'],
        );

        // E no outro extremo: a turma toda no ficheiro menos um.
        $builder = IntuitivoWorkbookBuilder::make()->group('GRUPO I', [2, 2]);

        foreach (range(2, 23) as $number) {
            $builder->student($this->names[$number], [1, 2]);
        }

        $issue = $this->missingIssue($this->preview($this->uploadWorkbook($builder)));

        $this->assertSame(
            '22 alunos com resultados para importar. 1 aluno da turma sem resultados neste ficheiro. Poderá preencher as respetivas classificações na grelha de correção.',
            $issue['message'],
        );
    }

    #[Test]
    public function nothing_is_said_when_every_student_of_the_class_is_in_the_file(): void
    {
        $builder = IntuitivoWorkbookBuilder::make()->group('GRUPO I', [2, 2]);

        foreach (range(1, 23) as $number) {
            $builder->student($this->names[$number], [1, 2]);
        }

        $this->assertNull($this->missingIssue($this->preview($this->uploadWorkbook($builder))));
    }

    #[Test]
    public function a_row_the_teacher_ignores_is_not_counted_as_having_results(): void
    {
        $import = $this->uploadWorkbook();
        $preview = $this->preview($import);

        $ignored = $preview['students'][0]['source_key'];

        $this->actingAs($this->teacher)->patch(
            "/imports/correction/{$import->ulid}",
            $this->createMapping($preview, [$ignored => null]),
        );

        $issue = $this->missingIssue($this->preview($import));

        $this->assertSame(20, $issue['context']['with_results']);
        $this->assertSame(3, $issue['context']['missing']);
    }

    // ---------------------------------------------- e. who the date applies to

    #[Test]
    public function a_student_who_left_before_the_application_date_is_not_without_results(): void
    {
        $this->setEnrollmentDates(7, left: now()->subDays(30)->toDateString());

        $import = $this->uploadWorkbook();
        $preview = $this->preview($this->patched($import));

        $issue = $this->missingIssue($preview);

        $this->assertSame(21, $issue['context']['with_results']);
        $this->assertSame(1, $issue['context']['missing'], 'Só o 19 fez a prova em papel; o 7 já tinha saído.');
        $this->assertStringContainsString('1 aluno da turma sem resultados', $issue['message']);
    }

    #[Test]
    public function a_student_who_enrolled_after_the_application_date_is_not_without_results(): void
    {
        $this->setEnrollmentDates(7, enrolled: now()->subDay()->toDateString());
        $this->setEnrollmentDates(19, enrolled: now()->toDateString());

        $import = $this->uploadWorkbook();

        $this->assertNull(
            $this->missingIssue($this->preview($this->patched($import))),
            'Os dois entraram depois de o teste ser aplicado: ninguém da turma ficou sem resultado.',
        );
    }

    #[Test]
    public function without_a_date_the_whole_class_counts_and_with_a_date_only_who_it_applies_to(): void
    {
        $this->setEnrollmentDates(7, left: now()->subDays(30)->toDateString());

        $import = $this->uploadWorkbook();

        // Sem data (nada escolhido ainda): o recurso `all()`, igual ao do
        // emparelhamento.
        $this->assertSame(2, $this->missingIssue($this->preview($import))['context']['missing']);

        // Com a data de aplicação, o aluno que já tinha saído deixa de contar.
        $this->assertSame(1, $this->missingIssue($this->preview($this->patched($import)))['context']['missing']);
    }

    protected function setEnrollmentDates(int $classNumber, ?string $left = null, ?string $enrolled = null): void
    {
        app(CurrentOrganization::class)->runFor($this->organization, function () use ($classNumber, $left, $enrolled): void {
            Enrollment::find($this->enrollmentByNumber[$classNumber])->forceFill(array_filter([
                'left_on' => $left,
                'enrolled_on' => $enrolled,
            ]))->save();
        });
    }

    /**
     * Guarda a decisão completa da criação do instrumento e devolve o import.
     */
    protected function patched(CorrectionImport $import): CorrectionImport
    {
        $this->actingAs($this->teacher)->patch(
            "/imports/correction/{$import->ulid}",
            $this->createMapping($this->preview($import)),
        );

        return $import;
    }

    // ------------------------------------------- f. matching by name, not place

    #[Test]
    public function rows_are_paired_by_name_and_never_by_position(): void
    {
        $preview = $this->preview($this->uploadWorkbook());

        $this->assertCount(21, $preview['students']);

        foreach ($preview['students'] as $row) {
            $number = array_search($row['display_name'], $this->names, true);

            $this->assertNotFalse($number);
            $this->assertSame('matched', $row['status']);
            $this->assertSame($this->enrollmentByNumber[$number], $row['enrollment_id'], "{$row['display_name']} ficou com a matrícula de outro aluno.");
        }

        // A premissa do teste: o ficheiro NÃO está pela ordem da turma.
        $fileOrder = array_map(fn (array $row): int => array_search($row['display_name'], $this->names, true), $preview['students']);
        $sorted = $fileOrder;
        sort($sorted);
        $this->assertNotSame($sorted, $fileOrder);

        // Os dois do papel não aparecem como emparelhados com ninguém.
        $paired = array_column($preview['students'], 'enrollment_id');

        foreach (self::PAPER_NUMBERS as $number) {
            $this->assertNotContains($this->enrollmentByNumber[$number], $paired);
        }
    }

    // ------------------------------------------------- b. after confirming

    #[Test]
    public function confirming_writes_only_what_the_file_judged_into_one_instrument_for_the_whole_class(): void
    {
        $instrument = $this->confirmCreate();

        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            $this->assertSame(1, Instrument::count(), 'Nenhum outro instrumento é criado.');
        });

        $items = $this->itemIds($instrument);
        $this->assertCount(16, $items);

        // A grelha devolve os 23 alunos, pela ordem da turma.
        $this->actingAs($this->teacher)
            ->get("/instruments/{$instrument->ulid}")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $students = $page->toArray()['props']['students'];

                $this->assertCount(23, $students);
                $this->assertSame(range(1, 23), array_column($students, 'class_number'));
                $this->assertSame(
                    array_map(fn (int $number): string => $this->names[$number], range(1, 23)),
                    array_column($students, 'name'),
                );
            });

        // 21 × 16 notas, cada uma na questão certa.
        $scores = $this->scoresOf($instrument);
        $this->assertCount(21 * 16, $scores);

        foreach ($this->names as $number => $name) {
            foreach ($items as $question => $itemId) {
                $key = $this->enrollmentByNumber[$number].':'.$itemId;

                if (in_array($number, self::PAPER_NUMBERS, true)) {
                    $this->assertArrayNotHasKey($key, $scores, "{$name} fez a prova em papel: não pode ter nota importada.");

                    continue;
                }

                $this->assertArrayHasKey($key, $scores);
                $this->assertSame('assessed', $scores[$key]->result_state->value);
                $this->assertEqualsWithDelta($this->markFor($number, $question), (float) $scores[$key]->points_earned, 0.0001, "{$name}, questão ".($question + 1));
            }
        }

        // Os do papel: nenhuma linha, nem zero, nem falta.
        foreach (self::PAPER_NUMBERS as $number) {
            $this->assertSame(
                0,
                app(CurrentOrganization::class)->runFor(
                    $this->organization,
                    fn () => StudentItemScore::where('enrollment_id', $this->enrollmentByNumber[$number])->count(),
                ),
            );
        }

        // E cada questão leva o domínio da sua secção.
        $this->assertSame(
            ['Leitura', 'Leitura', 'Leitura', 'Leitura', 'Leitura', 'Leitura', 'Gramática', 'Gramática', 'Gramática', 'Gramática', 'Gramática', 'Escrita', 'Escrita', 'Escrita', 'Escrita', 'Escrita'],
            app(CurrentOrganization::class)->runFor($this->organization, function () use ($items): array {
                return array_map(
                    fn (int $itemId): string => Domain::find(ItemDomainAllocation::where('instrument_item_id', $itemId)->firstOrFail()->domain_id)->name,
                    $items,
                );
            }),
        );
    }

    // ---------------------------------------------- c. filling in the rest

    #[Test]
    public function the_paper_students_are_filled_in_by_hand_without_touching_the_imported_marks(): void
    {
        $instrument = $this->confirmCreate();
        $items = $this->itemIds($instrument);

        $before = $this->scoresOf($instrument);

        // O professor preenche os dois do papel, questão a questão. O 7 recebe
        // EXACTAMENTE as notas do 8 e o 19 as do 20 — para que os totais
        // calculados possam ser comparados com os de alunos importados.
        $copyOf = [7 => 8, 19 => 20];
        $cells = [];

        foreach ($copyOf as $paper => $imported) {
            foreach ($items as $question => $itemId) {
                $cells[] = [
                    'enrollment_id' => $this->enrollmentByNumber[$paper],
                    'instrument_item_id' => $itemId,
                    'result_state' => 'assessed',
                    'points_earned' => (float) $this->markFor($imported, $question),
                    'lock_version' => 0,
                ];
            }
        }

        app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn () => app(RecordScores::class)->save($instrument, $cells, $this->teacher),
        );

        $after = $this->scoresOf($instrument);
        $this->assertCount(23 * 16, $after);

        // As 21 × 16 notas importadas ficaram como estavam.
        foreach ($before as $key => $score) {
            $this->assertSame((string) $score->points_earned, (string) $after[$key]->points_earned);
            $this->assertSame($score->lock_version, $after[$key]->lock_version, 'Uma nota importada foi reescrita.');
        }

        // Totais por domínio e global: o mesmo critério para quem foi importado
        // e para quem foi preenchido à mão.
        $official = app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn () => app(BuildResultsAnalysis::class)->officialCells($instrument->fresh()),
        )['students'];

        $this->assertCount(23, $official);

        foreach ($copyOf as $paper => $imported) {
            $manual = $official[$this->enrollmentByNumber[$paper]];
            $fromFile = $official[$this->enrollmentByNumber[$imported]];

            // Não é um empate de nulos: há mesmo classificação e totais.
            $this->assertNotEmpty($fromFile['global']);
            $this->assertNotEmpty($fromFile['domains']);

            $this->assertSame($fromFile['status'], $manual['status']);
            $this->assertSame($fromFile['global'], $manual['global'], "Classificação global do aluno {$paper} difere da de {$imported}.");
            $this->assertSame($fromFile['domains'], $manual['domains'], "Totais por domínio do aluno {$paper} diferem dos de {$imported}.");
        }
    }

    // --------------------------------------------------------- d. re-import

    #[Test]
    public function reimporting_never_replaces_a_hand_edited_mark_unless_the_teacher_says_so(): void
    {
        $instrument = $this->confirmCreate();
        $items = $this->itemIds($instrument);

        // Os dois do papel, preenchidos à mão.
        $cells = [];

        foreach (self::PAPER_NUMBERS as $paper) {
            foreach ($items as $question => $itemId) {
                $cells[] = [
                    'enrollment_id' => $this->enrollmentByNumber[$paper],
                    'instrument_item_id' => $itemId,
                    'result_state' => 'assessed',
                    'points_earned' => 1.0,
                    'lock_version' => 0,
                ];
            }
        }

        // E uma nota importada (aluno 5, questão 1) corrigida à mão: o ficheiro
        // dá-lhe markFor(5, 0); o professor põe outra.
        $edited = $this->enrollmentByNumber[5].':'.$items[0];
        $original = $this->scoresOf($instrument)[$edited];
        $handValue = $this->markFor(5, 0) === 1 ? 2.0 : 1.0;

        $cells[] = [
            'enrollment_id' => $this->enrollmentByNumber[5],
            'instrument_item_id' => $items[0],
            'result_state' => 'assessed',
            'points_earned' => $handValue,
            'lock_version' => $original->lock_version,
        ];

        app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn () => app(RecordScores::class)->save($instrument, $cells, $this->teacher),
        );

        $paperBefore = $this->paperRows($instrument);
        $this->assertCount(32, $paperBefore);

        // Primeira reimportação: sem escolher nada, a nota manual fica.
        $this->reimport($instrument, $items, []);

        $scores = $this->scoresOf($instrument);
        $this->assertEqualsWithDelta($handValue, (float) $scores[$edited]->points_earned, 0.0001, 'Sem CONFLICT_IMPORT a nota manual tem de ser preservada.');
        $this->assertSame($paperBefore, $this->paperRows($instrument), 'As provas em papel não podem ser tocadas.');
        $this->assertCount(23 * 16, $scores);

        // Segunda: com CONFLICT_IMPORT explícito, é substituída.
        $this->reimport($instrument, $items, [$edited => ImportMapping::CONFLICT_IMPORT]);

        $scores = $this->scoresOf($instrument);
        $this->assertEqualsWithDelta($this->markFor(5, 0), (float) $scores[$edited]->points_earned, 0.0001);
        $this->assertSame($paperBefore, $this->paperRows($instrument), 'As provas em papel continuam por tocar.');
        $this->assertCount(23 * 16, $scores);

        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            $this->assertSame(1, Instrument::count(), 'Reimportar não cria um segundo instrumento.');
        });
    }

    /**
     * @param  list<int>  $items
     * @param  array<string, string>  $conflicts  "matrícula:questão" => escolha
     */
    protected function reimport(Instrument $instrument, array $items, array $conflicts): void
    {
        $import = $this->uploadWorkbook();
        $preview = $this->preview($import);

        $students = [];

        foreach ($preview['students'] as $row) {
            $students[$row['source_key']] = $row['enrollment_id'];
        }

        $itemMap = [];

        foreach ($preview['items'] as $index => $item) {
            $itemMap[$item['source_key']] = $items[$index];
        }

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", [
            'mode' => ImportMapping::MODE_ASSOCIATE,
            'result_mode' => ImportMapping::RESULT_PER_QUESTION,
            'instrument_id' => $instrument->getKey(),
            'students' => $students,
            'items' => $itemMap,
            'conflicts' => $conflicts,
        ]);

        // O aviso continua a dizer a verdade com o instrumento já escolhido.
        $issue = $this->missingIssue($this->preview($import));
        $this->assertSame(21, $issue['context']['with_results']);
        $this->assertSame(2, $issue['context']['missing']);

        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertRedirect();
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    protected function paperRows(Instrument $instrument): array
    {
        $rows = [];
        $paperIds = array_map(fn (int $number): int => $this->enrollmentByNumber[$number], self::PAPER_NUMBERS);

        foreach ($this->scoresOf($instrument) as $key => $score) {
            if (in_array($score->enrollment_id, $paperIds, true)) {
                $rows[$key] = [(string) $score->points_earned, $score->lock_version, $score->result_state->value];
            }
        }

        ksort($rows);

        return $rows;
    }

    // ------------------------------------------------------- g. the exports

    #[Test]
    public function the_exports_list_the_class_in_the_order_of_the_class(): void
    {
        $instrument = $this->confirmCreate();
        $classUlid = $this->class->ulid;
        $periodUlid = $this->period->ulid;
        $expected = array_map(fn (int $number): string => $this->names[$number], range(1, 23));

        // O documento da grelha.
        $this->actingAs($this->teacher)
            ->get("/instruments/{$instrument->ulid}/impressao")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($expected): void {
                $rows = $page->toArray()['props']['document']['blocks'][0]['rows'];

                $this->assertSame($expected, array_column($rows, 'name'));
            });

        // O CSV da folha de avaliação.
        $csv = $this->actingAs($this->teacher)->get("/classes/{$classUlid}/pauta-avaliacao/csv/{$periodUlid}");
        $csv->assertOk();
        $this->assertNamesInOrder($expected, (string) $csv->getContent(), 'CSV da folha de avaliação');

        // O XLSX da folha de avaliação.
        $xlsx = $this->actingAs($this->teacher)->get("/classes/{$classUlid}/pauta-avaliacao/xlsx/{$periodUlid}");
        $xlsx->assertOk();

        $path = sys_get_temp_dir().'/lapis-parcial-'.bin2hex(random_bytes(4)).'.xlsx';
        file_put_contents($path, (string) $xlsx->getContent());

        try {
            $sheet = IOFactory::load($path);
            $text = '';

            foreach ($sheet->getAllSheets() as $worksheet) {
                foreach ($worksheet->toArray(null, true, true, false) as $row) {
                    $text .= implode("\t", array_map(fn ($cell): string => (string) $cell, $row))."\n";
                }
            }

            $this->assertNamesInOrder($expected, $text, 'XLSX da folha de avaliação');
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param  list<string>  $names  pela ordem esperada
     */
    protected function assertNamesInOrder(array $names, string $text, string $label): void
    {
        $last = -1;

        foreach ($names as $name) {
            $position = strpos($text, $name);

            $this->assertNotFalse($position, "{$label}: falta «{$name}».");
            $this->assertGreaterThan($last, $position, "{$label}: «{$name}» está fora da ordem da turma.");

            $last = $position;
        }
    }
}
