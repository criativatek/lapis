<?php

namespace Tests\Feature\Classes;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\StudentItemScore;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\DocxFixtureBuilder;
use Tests\Support\RosterFixture;
use Tests\TestCase;

/**
 * CORRIGIR UMA IMPORTAÇÃO SEM APAGAR A TURMA.
 *
 * A turma existe, os alunos existem, e o que veio errado foi o ficheiro. Até
 * aqui a única saída era apagar os alunos um a um e recomeçar — o que leva com
 * eles tudo o que estava pendurado no registo: resultados, classificações,
 * relatórios, autoavaliações, intervenções, evidências.
 *
 * Estes testes descrevem a saída que passou a existir, e sobretudo o que ela
 * NÃO faz. Nenhum aluno é eliminado, nenhum é duplicado, nenhuma avaliação é
 * tocada, e nada é escrito antes da confirmação final.
 */
class RosterReimportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Storage::fake('local');
    }

    protected function createClass(): SchoolClass
    {
        $context = app(CurrentOrganization::class)->runFor($this->user->personalOrganization(), fn (): array => [
            'year' => AcademicYear::factory()->recycle($this->user->personalOrganization())
                ->create(['starts_on' => '2026-09-14', 'ends_on' => '2027-06-30'])->id,
            'subject' => Subject::factory()->recycle($this->user->personalOrganization())->create()->id,
        ]);

        $this->actingAs($this->user)->post('/classes', [
            'label' => '7.º B',
            'academic_year_id' => $context['year'],
            'subject_id' => $context['subject'],
        ]);

        return SchoolClass::withoutGlobalScope('organization')->firstOrFail();
    }

    /**
     * Uploads a roster and returns the preview it produced. Writes nothing.
     *
     * @param  list<array{name: string, process_number?: string|null, situation?: string, class_number?: int|null}>  $students
     * @return array{token: string, rows: list<array<string, mixed>>, photos: list<array<string, mixed>>}
     */
    protected function previewRoster(SchoolClass $class, array $students): array
    {
        $excel = UploadedFile::fake()->createWithContent(
            'roster.xlsx',
            file_get_contents((new RosterFixture)->buildFrom($students)),
        );

        return $this->readPreview(
            $this->actingAs($this->user)->post("/classes/{$class->ulid}/roster-imports", ['roster' => $excel])
        );
    }

    /**
     * @return array{token: string, rows: list<array<string, mixed>>, photos: list<array<string, mixed>>}
     */
    protected function previewPhotos(SchoolClass $class, string $docxPath): array
    {
        $photos = UploadedFile::fake()->createWithContent('photos.docx', file_get_contents($docxPath));

        return $this->readPreview(
            $this->actingAs($this->user)->post("/classes/{$class->ulid}/photos", ['photos' => $photos])
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{token: string, rows: list<array<string, mixed>>, photos: list<array<string, mixed>>}
     */
    protected function attachPhotos(SchoolClass $class, string $token, array $rows, string $docxPath): array
    {
        $photos = UploadedFile::fake()->createWithContent('photos.docx', file_get_contents($docxPath));

        return $this->readPreview(
            $this->actingAs($this->user)->post(
                "/classes/{$class->ulid}/roster-imports/{$token}/photos",
                ['photos' => $photos, 'rows' => $rows],
            )
        );
    }

    /**
     * @return array{token: string, rows: list<array<string, mixed>>, photos: list<array<string, mixed>>}
     */
    protected function readPreview(TestResponse $response): array
    {
        $props = [];
        $response->assertInertia(function ($page) use (&$props) {
            $props = $page->toArray()['props'];

            return $page->component('roster-imports/Preview');
        });

        return [
            'token' => $props['token'],
            'rows' => $props['rows'],
            'photos' => $props['photos'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    protected function confirm(SchoolClass $class, string $token, array $rows): TestResponse
    {
        return $this->actingAs($this->user)
            ->post("/classes/{$class->ulid}/roster-imports/{$token}/confirm", ['rows' => $rows]);
    }

    /**
     * A class with two students already on the roll, imported normally — the
     * starting point of every correction below.
     *
     * @param  list<array{name: string, process_number?: string|null, situation?: string, class_number?: int|null}>|null  $students
     */
    protected function classWithStudents(?array $students = null): SchoolClass
    {
        $class = $this->createClass();

        $students ??= [
            ['name' => 'Maria Silva', 'process_number' => '1001'],
            ['name' => 'João Costa', 'process_number' => '1002'],
        ];

        $preview = $this->previewRoster($class, $students);
        $this->confirm($class, $preview['token'], $preview['rows']);

        return $class;
    }

    /**
     * @return Collection<int, Enrollment>
     */
    protected function roll(SchoolClass $class): Collection
    {
        return app(CurrentOrganization::class)->runFor(
            $this->user->personalOrganization(),
            fn () => Enrollment::query()->where('class_id', $class->id)->with('student.identity')->get(),
        );
    }

    protected function identityOf(SchoolClass $class, string $name): StudentIdentity
    {
        $enrollment = $this->roll($class)
            ->first(fn (Enrollment $enrollment): bool => $enrollment->student?->identity?->display_name === $name);

        $this->assertNotNull($enrollment, "Nenhum aluno chamado «{$name}» nesta turma.");

        return $enrollment->student->identity;
    }

    /**
     * Os nomes que a turma tem, para se poder afirmar que um deles NÃO lá está
     * — `identityOf()` afirma o contrário e não serve para isso.
     *
     * @return list<string>
     */
    protected function namesOn(SchoolClass $class): array
    {
        return $this->roll($class)
            ->map(fn (Enrollment $enrollment): ?string => $enrollment->student?->identity?->display_name)
            ->filter()
            ->values()
            ->all();
    }

    // ── O caso observado no 7.º B ────────────────────────────────────────────

    #[Test]
    public function a_photo_file_exported_without_names_still_reaches_the_preview_to_be_assigned_by_hand(): void
    {
        $class = $this->classWithStudents();

        // O ficheiro EB019 exportado sem «colocar o nome ao lado da foto»: as
        // fotos lá estão, e não há uma única legenda. Antes, o parser
        // devolvia uma lista vazia e o professor ficava sem nada.
        $preview = $this->previewPhotos($class, DocxFixtureBuilder::buildWithoutCaptions([
            DocxFixtureBuilder::tinyJpeg(),
            DocxFixtureBuilder::tinyJpeg(),
        ]));

        $this->assertCount(2, $preview['photos']);
        $this->assertFalse($preview['photos'][0]['named']);
        $this->assertFalse($preview['photos'][1]['named']);

        // E não são associadas a ninguém por adivinhação: uma foto sem nome
        // nunca corresponde automaticamente a um aluno.
        foreach ($preview['rows'] as $row) {
            $this->assertNull($row['photo_index']);
        }

        // A pré-visualização não escreveu nada.
        foreach ($this->roll($class) as $enrollment) {
            $this->assertNull($enrollment->student->identity->photo_path);
        }
    }

    #[Test]
    public function assigning_an_unnamed_photo_by_hand_updates_only_that_students_photo(): void
    {
        $class = $this->classWithStudents();
        $before = $this->roll($class);

        $preview = $this->previewPhotos($class, DocxFixtureBuilder::buildWithoutCaptions([
            DocxFixtureBuilder::tinyJpeg(),
        ]));

        // O professor aponta a foto 0 à primeira linha — é o que o picker
        // manual da pré-visualização faz.
        $rows = $preview['rows'];
        $rows[0]['photo_index'] = 0;
        $rows[0]['photo_extension'] = 'jpg';
        $rows[0]['include'] = true;

        $this->confirm($class, $preview['token'], $rows)->assertRedirect();

        $after = $this->roll($class);

        $this->assertCount($before->count(), $after);
        $this->assertNotNull($after->firstWhere('id', $rows[0]['enrollment_id'])->student->identity->photo_path);

        // Ninguém foi criado, ninguém foi eliminado, e o aluno é o mesmo
        // aluno: o pseudonym_code é a identidade técnica de que dependem
        // todos os resultados.
        $this->assertSame(
            $before->pluck('student.pseudonym_code')->sort()->values()->all(),
            $after->pluck('student.pseudonym_code')->sort()->values()->all(),
        );
    }

    #[Test]
    public function the_photo_correction_flow_writes_nothing_until_it_is_confirmed(): void
    {
        $class = $this->classWithStudents();

        $this->previewPhotos($class, DocxFixtureBuilder::build([
            ['name' => 'Maria Silva', 'imageBytes' => DocxFixtureBuilder::tinyJpeg()],
        ]));

        foreach ($this->roll($class) as $enrollment) {
            $this->assertNull($enrollment->student->identity->photo_path);
        }

        Storage::disk('local')->assertDirectoryEmpty('student-photos');
    }

    #[Test]
    public function a_reimport_of_the_roster_attaches_photos_to_the_students_already_enrolled(): void
    {
        // O caso mais simples do pedido (§8): a turma já tem todos os alunos
        // certos, só as fotos é que faltam. Antes, este caminho escrevia a
        // foto no disco e apagava-a a seguir.
        $class = $this->classWithStudents();
        $before = $this->roll($class);

        $preview = $this->previewRoster($class, [
            ['name' => 'Maria Silva', 'process_number' => '1001'],
            ['name' => 'João Costa', 'process_number' => '1002'],
        ]);

        $this->assertSame('update', $preview['rows'][0]['action']);
        $this->assertSame('update', $preview['rows'][1]['action']);

        $withPhotos = $this->attachPhotos(
            $class,
            $preview['token'],
            $preview['rows'],
            DocxFixtureBuilder::build([
                ['name' => 'Maria Silva', 'imageBytes' => DocxFixtureBuilder::tinyJpeg()],
                ['name' => 'João Costa', 'imageBytes' => DocxFixtureBuilder::tinyJpeg()],
            ]),
        );

        $this->confirm($class, $withPhotos['token'], $withPhotos['rows'])->assertRedirect();

        $after = $this->roll($class);

        $this->assertCount(2, $after, '0 alunos eliminados, 0 duplicados.');

        foreach ($after as $enrollment) {
            $this->assertNotNull($enrollment->student->identity->photo_path);
        }

        $this->assertSame(
            $before->pluck('id')->sort()->values()->all(),
            $after->pluck('id')->sort()->values()->all(),
            'As mesmas inscrições, não outras.',
        );
    }

    #[Test]
    public function replacing_a_photo_removes_the_file_it_replaced(): void
    {
        $class = $this->classWithStudents();

        foreach ([1, 2] as $round) {
            $preview = $this->previewPhotos($class, DocxFixtureBuilder::build([
                ['name' => 'Maria Silva', 'imageBytes' => DocxFixtureBuilder::tinyJpeg()],
            ]));

            $this->confirm($class, $preview['token'], $preview['rows'])->assertRedirect();

            if ($round === 1) {
                $firstPath = $this->identityOf($class, 'Maria Silva')->photo_path;
                Storage::disk('local')->assertExists($firstPath);
            }
        }

        $secondPath = $this->identityOf($class, 'Maria Silva')->photo_path;

        Storage::disk('local')->assertExists($secondPath);
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('local')->assertMissing($firstPath);
    }

    // ── Nomes ────────────────────────────────────────────────────────────────

    #[Test]
    public function a_corrected_spelling_updates_the_existing_student_instead_of_adding_a_second_one(): void
    {
        $class = $this->classWithStudents();
        $before = $this->roll($class);

        $preview = $this->previewRoster($class, [
            ['name' => 'Maria Silva Andrade', 'process_number' => '1001'],
            ['name' => 'João Costa', 'process_number' => '1002'],
        ]);

        // Reconhecido pelo n.º de processo, e o preview diz «Nome atual →
        // Nome novo» em vez de só o destino (§5).
        $this->assertSame('process_number', $preview['rows'][0]['matched_by']);
        $this->assertTrue($preview['rows'][0]['name_changes']);
        $this->assertSame('Maria Silva', $preview['rows'][0]['current_name']);

        $this->confirm($class, $preview['token'], $preview['rows'])->assertRedirect();

        $after = $this->roll($class);

        $this->assertCount(2, $after, 'Um nome corrigido não é um segundo aluno.');
        $this->assertSame(
            $before->pluck('student_id')->sort()->values()->all(),
            $after->pluck('student_id')->sort()->values()->all(),
        );
        $this->assertNotNull($this->identityOf($class, 'Maria Silva Andrade'));
    }

    /**
     * Um aluno inscrito à mão, com o n.º de processo ao lado.
     *
     * Duas inscrições com o MESMO nome não se conseguem criar por importação —
     * a pré-visualização marca as duas linhas como duplicadas no ficheiro e
     * não inscreve nenhuma, que é o comportamento certo. Mas uma turma chega a
     * esse estado por outros caminhos (dois alunos que se chamam mesmo o
     * mesmo, um aluno inscrito à mão em cima de um importado), e é aí que um
     * matching por nome deixa de poder decidir seja o que for.
     */
    protected function enrollByHand(SchoolClass $class, string $name, ?int $classNumber = null, ?string $processNumber = null): Enrollment
    {
        $this->actingAs($this->user)->post("/classes/{$class->ulid}/students", [
            'name' => $name,
            'class_number' => $classNumber,
            'enrolled_on' => '2026-09-14',
        ]);

        $enrollment = app(CurrentOrganization::class)->runFor(
            $this->user->personalOrganization(),
            fn () => Enrollment::query()->where('class_id', $class->id)->orderByDesc('id')->firstOrFail(),
        );

        if ($processNumber !== null) {
            $this->actingAs($this->user)->put("/classes/{$class->ulid}/process-numbers", [
                'numbers' => [['enrollment_ulid' => $enrollment->ulid, 'process_number' => $processNumber]],
            ]);
        }

        return $enrollment;
    }

    #[Test]
    public function the_process_number_decides_when_a_name_would_have_been_ambiguous(): void
    {
        $class = $this->createClass();
        $this->enrollByHand($class, 'Maria Silva', 1, '1001');
        $second = $this->enrollByHand($class, 'Maria Silva', 2, '1002');

        $preview = $this->previewRoster($class, [
            ['name' => 'Maria Silva Andrade', 'process_number' => '1002'],
        ]);

        $this->assertSame('process_number', $preview['rows'][0]['matched_by']);
        $this->assertFalse($preview['rows'][0]['ambiguous']);
        $this->assertSame($second->id, $preview['rows'][0]['enrollment_id']);
        $this->assertSame('update', $preview['rows'][0]['action']);
    }

    #[Test]
    public function two_students_with_the_same_name_and_no_identifier_are_reported_ambiguous_and_nothing_is_written(): void
    {
        $class = $this->createClass();
        $this->enrollByHand($class, 'Maria Silva', 1);
        $this->enrollByHand($class, 'Maria Silva', 2);

        $preview = $this->previewRoster($class, [
            ['name' => 'Maria Silva'],
        ]);

        $this->assertTrue($preview['rows'][0]['ambiguous']);
        $this->assertSame('ambiguous', $preview['rows'][0]['action']);
        $this->assertFalse($preview['rows'][0]['include'], 'Uma linha ambígua não entra por omissão.');
        $this->assertNull($preview['rows'][0]['enrollment_id'], 'Não se escolhe um dos dois por eles.');
        $this->assertCount(2, $preview['rows'][0]['candidates']);

        $this->confirm($class, $preview['token'], $preview['rows'])->assertRedirect();

        $this->assertCount(2, $this->roll($class), 'Nem inscreveu um terceiro, nem tocou nos dois.');
    }

    #[Test]
    public function the_teacher_resolving_an_ambiguity_updates_the_enrolment_they_chose(): void
    {
        $class = $this->createClass();
        $this->enrollByHand($class, 'Maria Silva', 1);
        $chosen = $this->enrollByHand($class, 'Maria Silva', 2);

        $preview = $this->previewRoster($class, [['name' => 'Maria Silva']]);

        $this->assertTrue($preview['rows'][0]['ambiguous']);

        // O que a caixa de seleção da pré-visualização faz quando o professor
        // aponta uma das duas: nomeia a inscrição e passa a incluir a linha.
        $rows = $preview['rows'];
        $rows[0]['enrollment_id'] = $chosen->id;
        $rows[0]['name'] = 'Maria Silva Andrade';
        $rows[0]['include'] = true;

        $this->confirm($class, $preview['token'], $rows)->assertRedirect();

        $roll = $this->roll($class);

        $this->assertCount(2, $roll, 'Escolher um dos dois não cria um terceiro.');
        $this->assertSame(
            'Maria Silva Andrade',
            $roll->firstWhere('id', $chosen->id)->student->identity->display_name,
        );
        $this->assertSame(
            'Maria Silva',
            $roll->firstWhere('id', '!=', $chosen->id)->student->identity->display_name,
            'A outra ficou exatamente como estava.',
        );
    }

    // ── Alunos novos, duplicados e linhas sem nada ───────────────────────────

    #[Test]
    public function a_student_missing_from_the_first_import_is_added_without_duplicating_anybody(): void
    {
        $class = $this->classWithStudents();

        $preview = $this->previewRoster($class, [
            ['name' => 'Maria Silva', 'process_number' => '1001'],
            ['name' => 'João Costa', 'process_number' => '1002'],
            ['name' => 'Rita Nunes', 'process_number' => '1003'],
        ]);

        $this->assertSame('update', $preview['rows'][0]['action']);
        $this->assertSame('update', $preview['rows'][1]['action']);
        $this->assertSame('enrol', $preview['rows'][2]['action']);

        $this->confirm($class, $preview['token'], $preview['rows'])->assertRedirect();

        $this->assertCount(3, $this->roll($class));
    }

    /**
     * DUAS LINHAS PARA O MESMO ALUNO SÃO UM DUPLICADO, MESMO COM NOMES
     * DIFERENTES.
     *
     * Um n.º de processo repetido num ficheiro feito à mão é um engano
     * corrente, e as duas linhas resolvem — sem ambiguidade nenhuma, porque
     * cada uma encontra exatamente uma inscrição — para o MESMO aluno. Sem
     * guarda, a segunda escrevia por cima da primeira em silêncio.
     */
    #[Test]
    public function two_rows_pointing_at_the_same_student_are_flagged_and_neither_is_applied(): void
    {
        $class = $this->classWithStudents();

        $preview = $this->previewRoster($class, [
            ['name' => 'Maria Silva', 'process_number' => '1001'],
            ['name' => 'Rita Nunes', 'process_number' => '1001'],
        ]);

        foreach ($preview['rows'] as $position => $row) {
            // «Duas linhas, um só aluno» e «o mesmo nome duas vezes» são
            // duplicados diferentes e dizem-se com palavras diferentes: aqui
            // os nomes não se parecem nada, e mandar procurar um nome repetido
            // seria mandar procurar o que não existe.
            $this->assertTrue($row['duplicate_target'], "Linha {$position}");
            $this->assertFalse($row['duplicate_in_file'], "Linha {$position}");
            $this->assertSame('skip', $row['action'], "Linha {$position}");
            $this->assertFalse($row['include'], "Linha {$position}");
        }

        $this->confirm($class, $preview['token'], $preview['rows'])->assertRedirect();

        // O nome de quem lá estava não foi trocado pelo da outra linha.
        $this->assertCount(2, $this->roll($class));
        $this->assertNotNull($this->identityOf($class, 'Maria Silva'));
        $this->assertNotContains('Rita Nunes', $this->namesOn($class));
    }

    /**
     * A marcação da pré-visualização é apresentação; quem recusa é o servidor.
     *
     * As linhas chegam do cliente, e nada impede que duas tragam o mesmo
     * `enrollment_id` — um separador antigo, uma ambiguidade resolvida duas
     * vezes para o mesmo aluno, um pedido feito à mão. A primeira aplica-se;
     * a segunda não escreve por cima dela.
     */
    #[Test]
    public function the_server_writes_each_enrolment_once_even_when_the_client_sends_it_twice(): void
    {
        $class = $this->classWithStudents();

        $preview = $this->previewRoster($class, [
            ['name' => 'Maria Silva', 'process_number' => '1001'],
        ]);

        $enrollmentId = $preview['rows'][0]['enrollment_id'];
        $this->assertNotNull($enrollmentId);

        $rows = [
            [...$preview['rows'][0], 'name' => 'Maria Silva Andrade', 'include' => true],
            [...$preview['rows'][0], 'name' => 'Rita Impostora', 'include' => true],
        ];

        $this->confirm($class, $preview['token'], $rows)->assertRedirect();

        // Um aluno, um nome — o da primeira linha —, e ninguém acrescentado.
        $this->assertCount(2, $this->roll($class));
        $this->assertNotNull($this->identityOf($class, 'Maria Silva Andrade'));
        $this->assertNotContains('Rita Impostora', $this->namesOn($class));
    }

    #[Test]
    public function the_same_name_twice_in_one_file_is_skipped_rather_than_guessed_about(): void
    {
        $class = $this->createClass();

        $preview = $this->previewRoster($class, [
            ['name' => 'Rita Nunes'],
            ['name' => 'Rita Nunes'],
        ]);

        foreach ($preview['rows'] as $row) {
            $this->assertTrue($row['duplicate_in_file']);
            $this->assertSame('skip', $row['action']);
            $this->assertFalse($row['include']);
        }

        $this->confirm($class, $preview['token'], $preview['rows'])->assertRedirect();

        $this->assertCount(0, $this->roll($class));
    }

    #[Test]
    public function a_row_with_no_name_and_nobody_on_the_roll_to_match_is_skipped_instead_of_breaking_the_import(): void
    {
        $class = $this->classWithStudents();

        $preview = $this->previewRoster($class, [['name' => 'Maria Silva', 'process_number' => '1001']]);

        $rows = $preview['rows'];
        $rows[] = [...$rows[0], 'name' => '', 'enrollment_id' => null, 'process_number' => null, 'include' => true];

        $this->confirm($class, $preview['token'], $rows)->assertRedirect();

        $this->assertCount(2, $this->roll($class), 'Nenhum aluno sem nome foi criado.');
    }

    #[Test]
    public function a_roster_whose_situation_column_is_empty_can_still_be_confirmed(): void
    {
        // A coluna «SIT.» vazia chega ao servidor como null (o middleware
        // ConvertEmptyStringsToNull), e a regra `required` recusava a
        // importação inteira — de um ficheiro que a própria pré-visualização
        // já sabia explicar.
        $class = $this->createClass();

        $preview = $this->previewRoster($class, [['name' => 'Rita Nunes', 'situation' => '']]);

        $this->confirm($class, $preview['token'], $preview['rows'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertCount(1, $this->roll($class));
    }

    // ── História ─────────────────────────────────────────────────────────────

    #[Test]
    public function a_reimport_leaves_scores_and_the_students_technical_identity_untouched(): void
    {
        $class = $this->classWithStudents();
        $enrollment = $this->roll($class)->first();
        $organization = $this->user->personalOrganization();

        $score = app(CurrentOrganization::class)->runFor(
            $organization,
            fn () => StudentItemScore::factory()->recycle($organization)->create([
                'enrollment_id' => $enrollment->id,
                'result_state' => 'assessed',
                'points_earned' => 7,
            ]),
        );

        $studentCountBefore = Student::withoutGlobalScope('organization')->count();

        $preview = $this->previewRoster($class, [
            ['name' => 'Maria Silva Andrade', 'process_number' => '1001'],
            ['name' => 'João Costa', 'process_number' => '1002'],
        ]);

        $withPhotos = $this->attachPhotos(
            $class,
            $preview['token'],
            $preview['rows'],
            DocxFixtureBuilder::buildWithoutCaptions([DocxFixtureBuilder::tinyJpeg()]),
        );

        $rows = $withPhotos['rows'];
        $rows[0]['photo_index'] = 0;
        $rows[0]['photo_extension'] = 'jpg';

        $this->confirm($class, $withPhotos['token'], $rows)->assertRedirect();

        $fresh = app(CurrentOrganization::class)->runFor(
            $organization,
            fn () => StudentItemScore::query()->find($score->id),
        );

        $this->assertNotNull($fresh, 'A reimportação não pode apagar um resultado.');
        $this->assertSame(7.0, (float) $fresh->points_earned, 'O resultado que lá estava continua a ser o que lá está.');
        $this->assertSame($enrollment->id, $fresh->enrollment_id, 'A inscrição é a mesma inscrição.');

        $this->assertSame(
            $studentCountBefore,
            Student::withoutGlobalScope('organization')->count(),
            'Nenhum Student foi criado nem eliminado.',
        );
    }

    #[Test]
    public function confirming_the_same_reimport_twice_changes_nothing_the_second_time(): void
    {
        $class = $this->classWithStudents();

        $students = [
            ['name' => 'Maria Silva', 'process_number' => '1001'],
            ['name' => 'João Costa', 'process_number' => '1002'],
        ];

        $first = $this->previewRoster($class, $students);
        $this->confirm($class, $first['token'], $first['rows'])->assertRedirect();

        $afterFirst = $this->roll($class)
            ->map(fn (Enrollment $enrollment): array => [
                'id' => $enrollment->id,
                'student_id' => $enrollment->student_id,
                'name' => $enrollment->student->identity->display_name,
                'status' => $enrollment->status->value,
            ])
            ->sortBy('id')->values()->all();

        $second = $this->previewRoster($class, $students);
        $this->confirm($class, $second['token'], $second['rows'])->assertRedirect();

        $afterSecond = $this->roll($class)
            ->map(fn (Enrollment $enrollment): array => [
                'id' => $enrollment->id,
                'student_id' => $enrollment->student_id,
                'name' => $enrollment->student->identity->display_name,
                'status' => $enrollment->status->value,
            ])
            ->sortBy('id')->values()->all();

        $this->assertSame($afterFirst, $afterSecond);
    }

    #[Test]
    public function the_preview_writes_nothing_at_all(): void
    {
        $class = $this->classWithStudents();
        $before = $this->roll($class)->count();

        $preview = $this->previewRoster($class, [
            ['name' => 'Maria Silva', 'process_number' => '1001'],
            ['name' => 'Rita Nunes', 'process_number' => '1003'],
        ]);

        $this->attachPhotos(
            $class,
            $preview['token'],
            $preview['rows'],
            DocxFixtureBuilder::build([
                ['name' => 'Maria Silva', 'imageBytes' => DocxFixtureBuilder::tinyJpeg()],
            ]),
        );

        $this->assertSame($before, $this->roll($class)->count());

        foreach ($this->roll($class) as $enrollment) {
            $this->assertNull($enrollment->student->identity->photo_path);
        }
    }

    #[Test]
    public function discarding_a_photo_correction_returns_to_the_photo_dialog_and_deletes_the_staged_photos(): void
    {
        $class = $this->classWithStudents();

        $preview = $this->previewPhotos($class, DocxFixtureBuilder::buildWithoutCaptions([
            DocxFixtureBuilder::tinyJpeg(),
        ]));

        Storage::disk('local')->assertExists("roster-imports/{$preview['token']}/0.jpg");

        $this->actingAs($this->user)
            ->delete("/classes/{$class->ulid}/roster-imports/{$preview['token']}?flow=photos")
            ->assertRedirect("/classes/{$class->ulid}?fotos=1");

        Storage::disk('local')->assertMissing("roster-imports/{$preview['token']}/0.jpg");
        $this->assertCount(2, $this->roll($class));
    }

    #[Test]
    public function the_server_refuses_to_write_the_same_enrolment_twice_even_when_the_client_insists(): void
    {
        // A marcação da pré-visualização é apresentação, e as linhas voltam do
        // cliente. Aqui as duas chegam marcadas para entrar, com o mesmo
        // destino — é o que um separador antigo ou um pedido feito à mão
        // produz. Sem a guarda no servidor, a segunda escrevia por cima da
        // primeira e a contagem dizia que tinham sido dois alunos.
        $class = $this->classWithStudents();

        $preview = $this->previewRoster($class, [
            ['name' => 'Maria Silva', 'process_number' => '1001'],
        ]);

        $first = [...$preview['rows'][0], 'include' => true];
        $second = [...$first, 'name' => 'Nome Que Nao Deve Ficar'];

        $this->confirm($class, $preview['token'], [$first, $second])
            ->assertRedirect()
            ->assertSessionHas(SessionKey::FLASH_DATA, fn (array $flash): bool => str_contains(
                $flash['toast']['message'],
                '1 aluno(s) atualizado(s)',
            ));

        $this->assertSame(
            'Maria Silva',
            $this->roll($class)->firstWhere('id', $first['enrollment_id'])
                ->student->identity->display_name,
            'A segunda linha não escreveu por cima da primeira.',
        );
    }
}
