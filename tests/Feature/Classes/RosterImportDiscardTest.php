<?php

namespace Tests\Feature\Classes;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Support\Import\RosterImportTempStorage;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\DocxFixtureBuilder;
use Tests\Support\RosterFixture;
use Tests\TestCase;

/**
 * SAIR DA PRÉ-VISUALIZAÇÃO SEM IMPORTAR.
 *
 * Até aqui a pré-visualização da relação de turma só sabia fazer uma coisa:
 * confirmar. Quem lá chegasse com o ficheiro errado — do ano passado, da turma
 * ao lado — tinha de sair pelo botão «anterior» do browser, que é um mecanismo
 * do browser e não da aplicação. A importação do horário já tinha «Escolher
 * outro ficheiro»; esta passa a ter o mesmo.
 *
 * E desistir desiste mesmo: a pasta temporária deste token, com as fotografias
 * dos alunos que lá estivessem, desaparece no próprio pedido em vez de esperar
 * pelo prune agendado.
 */
class RosterImportDiscardTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Storage::fake('local');
    }

    protected function createClass(?User $owner = null, string $label = '7.º A'): SchoolClass
    {
        $owner ??= $this->user;

        $context = app(CurrentOrganization::class)->runFor($owner->personalOrganization(), fn (): array => [
            'year' => AcademicYear::factory()->recycle($owner->personalOrganization())
                ->create(['starts_on' => '2026-09-14', 'ends_on' => '2027-06-30'])->id,
            'subject' => Subject::factory()->recycle($owner->personalOrganization())->create()->id,
        ]);

        $this->actingAs($owner)->post('/classes', [
            'label' => $label,
            'academic_year_id' => $context['year'],
            'subject_id' => $context['subject'],
        ]);

        return SchoolClass::withoutGlobalScope('organization')->where('label', $label)->firstOrFail();
    }

    /**
     * @return array{token: string, rows: array<int, mixed>}
     */
    protected function uploadRoster(SchoolClass $class): array
    {
        $response = $this->actingAs($this->user)->post("/classes/{$class->ulid}/roster-imports", [
            'roster' => UploadedFile::fake()->createWithContent(
                'roster.xlsx',
                file_get_contents((new RosterFixture)->build()),
            ),
        ]);

        $token = null;
        $rows = null;

        $response->assertInertia(function ($page) use (&$token, &$rows) {
            $token = $page->toArray()['props']['token'];
            $rows = $page->toArray()['props']['rows'];

            return $page->component('roster-imports/Preview');
        });

        return ['token' => $token, 'rows' => $rows];
    }

    // ----------------------------------------------------------- a saída

    #[Test]
    public function discarding_the_preview_returns_to_the_upload_step_without_enrolling_anyone(): void
    {
        $class = $this->createClass();
        $uploaded = $this->uploadRoster($class);

        $this->actingAs($this->user)
            ->delete("/classes/{$class->ulid}/roster-imports/{$uploaded['token']}")
            // De volta à turma — E ao passo de onde o ficheiro saiu: `importar`
            // reabre o diálogo de carregamento, para que escolher outro seja um
            // clique e não uma caça ao botão.
            ->assertRedirect("/classes/{$class->ulid}?importar=1");

        // O QUE DESISTIR TEM DE SIGNIFICAR: ninguém foi inscrito.
        $this->assertSame(0, Enrollment::withoutGlobalScopes()->where('class_id', $class->id)->count());
    }

    #[Test]
    public function discarding_deletes_the_photos_instead_of_leaving_them_for_the_scheduled_prune(): void
    {
        $class = $this->createClass();
        $uploaded = $this->uploadRoster($class);

        // As fotografias só chegam ao disco no segundo passo — é esse o estado
        // que interessa aqui: fotografias reais de alunos, à espera de uma
        // confirmação que não vai acontecer.
        $this->actingAs($this->user)->post(
            "/classes/{$class->ulid}/roster-imports/{$uploaded['token']}/photos",
            [
                'photos' => UploadedFile::fake()->createWithContent(
                    'photos.docx',
                    file_get_contents(DocxFixtureBuilder::build([
                        ['name' => 'Maria Teste', 'imageBytes' => DocxFixtureBuilder::tinyJpeg()],
                    ])),
                ),
                'rows' => $uploaded['rows'],
            ],
        );

        $folder = app(RosterImportTempStorage::class)->path($uploaded['token']);
        $this->assertNotEmpty(Storage::disk('local')->files($folder));

        $this->actingAs($this->user)
            ->delete("/classes/{$class->ulid}/roster-imports/{$uploaded['token']}")
            ->assertRedirect();

        $this->assertEmpty(Storage::disk('local')->files($folder));
    }

    #[Test]
    public function confirming_still_works_after_the_exit_was_added(): void
    {
        $class = $this->createClass();
        $uploaded = $this->uploadRoster($class);

        // A saída não pode ter partido a entrada. Confirmar continua a
        // inscrever exactamente como antes.
        $this->actingAs($this->user)->post(
            "/classes/{$class->ulid}/roster-imports/{$uploaded['token']}/confirm",
            ['rows' => $uploaded['rows']],
        )->assertRedirect("/classes/{$class->ulid}");

        $this->assertGreaterThan(
            0,
            Enrollment::withoutGlobalScopes()->where('class_id', $class->id)->count(),
        );
    }

    #[Test]
    public function a_second_upload_after_discarding_gets_a_preview_of_its_own(): void
    {
        $class = $this->createClass();
        $first = $this->uploadRoster($class);

        $this->actingAs($this->user)
            ->delete("/classes/{$class->ulid}/roster-imports/{$first['token']}")
            ->assertRedirect();

        // O CICLO INTEIRO, que é o ponto desta fatia: carregar → pré-ver →
        // escolher outro → carregar → pré-ver → confirmar, sem sair da
        // aplicação e sem tocar no «anterior» do browser.
        $second = $this->uploadRoster($class);

        $this->assertNotSame($first['token'], $second['token']);

        $this->actingAs($this->user)->post(
            "/classes/{$class->ulid}/roster-imports/{$second['token']}/confirm",
            ['rows' => $second['rows']],
        )->assertRedirect("/classes/{$class->ulid}");

        $this->assertGreaterThan(
            0,
            Enrollment::withoutGlobalScopes()->where('class_id', $class->id)->count(),
        );
    }

    #[Test]
    public function discarding_twice_is_harmless(): void
    {
        $class = $this->createClass();
        $uploaded = $this->uploadRoster($class);

        // O «anterior» do browser depois de desistir traz o professor de volta
        // a uma pré-visualização cujo token já não existe. Carregar outra vez
        // em «Escolher outro ficheiro» tem de o levar ao mesmo sítio, não a um
        // erro.
        foreach (range(1, 2) as $attempt) {
            $this->actingAs($this->user)
                ->delete("/classes/{$class->ulid}/roster-imports/{$uploaded['token']}")
                ->assertRedirect("/classes/{$class->ulid}?importar=1");
        }

        $this->assertSame(0, Enrollment::withoutGlobalScopes()->where('class_id', $class->id)->count());
    }

    // ---------------------------------------------------------- tenancy

    #[Test]
    public function a_teacher_from_another_organization_cannot_discard_this_import(): void
    {
        $class = $this->createClass();
        $uploaded = $this->uploadRoster($class);

        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->delete("/classes/{$class->ulid}/roster-imports/{$uploaded['token']}")
            ->assertNotFound();

        // E a pasta continua lá — recusar não é apagar o trabalho de outrem.
        $folder = app(RosterImportTempStorage::class)->path($uploaded['token']);
        $this->assertNotEmpty(Storage::disk('local')->files($folder));
    }

    #[Test]
    public function a_token_from_another_class_is_not_deleted_through_this_one(): void
    {
        $class = $this->createClass();
        $other = $this->createClass(label: '8.º B');
        $uploaded = $this->uploadRoster($other);

        // Mesmo professor, mesma organização, turma errada: a permissão sobre
        // esta turma não diz de quem é aquela pasta (a mesma guarda que
        // previewPhoto() e confirm() já faziam).
        $this->actingAs($this->user)
            ->delete("/classes/{$class->ulid}/roster-imports/{$uploaded['token']}")
            ->assertRedirect();

        $folder = app(RosterImportTempStorage::class)->path($uploaded['token']);
        $this->assertNotEmpty(Storage::disk('local')->files($folder));
    }
}
