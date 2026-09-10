<?php

namespace Tests\Feature\Classes;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\Instrument;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\Classes\SchoolClassHistory;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O CICLO DE VIDA DA TURMA — arquivar, restaurar, eliminar em definitivo.
 *
 * ARQUIVAR NUNCA APAGA NADA: é um `archived_at` a mais, ortogonal ao `status`,
 * e uma turma arquivada continua exactamente com os dados que tinha. ELIMINAR
 * EM DEFINITIVO só é possível depois de arquivada, só depois de decorridos
 * três anos sobre `academic_year.ends_on` — nunca sobre `created_at` ou
 * `archived_at` — e só se não sobrar história pedagógica nenhuma
 * (SchoolClassHistory, o mesmo espírito de EnrollmentHistory).
 */
class ClassArchiveLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /**
     * Uma turma nova, com o ano letivo a acabar na data pedida — a única
     * variável de que a elegibilidade para eliminação depende.
     */
    protected function createClassEndingOn(string $endsOn, ?User $owner = null): SchoolClass
    {
        $owner ??= $this->user;

        $context = app(CurrentOrganization::class)->runFor($owner->personalOrganization(), fn () => [
            'year' => AcademicYear::factory()->recycle($owner->personalOrganization())->create([
                'starts_on' => '2018-09-14',
                'ends_on' => $endsOn,
            ])->id,
            'subject' => Subject::factory()->recycle($owner->personalOrganization())->create()->id,
        ]);

        $this->actingAs($owner)->post('/classes', [
            'label' => '7.º A',
            'academic_year_id' => $context['year'],
            'subject_id' => $context['subject'],
        ]);

        return SchoolClass::withoutGlobalScope('organization')
            ->where('organization_id', $owner->personalOrganization()->id)
            ->latest('id')
            ->firstOrFail();
    }

    protected function createClass(?User $owner = null): SchoolClass
    {
        return $this->createClassEndingOn('2027-06-30', $owner);
    }

    protected function enroll(SchoolClass $class, string $name, ?User $owner = null): Enrollment
    {
        $owner ??= $this->user;

        $this->actingAs($owner)->post("/classes/{$class->ulid}/students", [
            'name' => $name,
            'enrolled_on' => $class->academicYear->starts_on->toDateString(),
        ]);

        return Enrollment::withoutGlobalScope('organization')
            ->where('class_id', $class->id)
            ->latest('id')
            ->firstOrFail();
    }

    /**
     * @return array{type: string, message: string}
     */
    protected function toast(): array
    {
        $flash = session('inertia.flash_data');

        $this->assertIsArray($flash, 'Nenhuma mensagem foi mostrada ao professor.');
        $this->assertArrayHasKey('toast', $flash);

        return $flash['toast'];
    }

    // ------------------------------------------------------- arquivar

    #[Test]
    public function a_class_must_be_archived_before_it_can_be_permanently_deleted(): void
    {
        $class = $this->createClass();

        // O DELETE direto, em bruto — não interessa o que a interface
        // escondia, o servidor tem de recusar sozinho.
        $response = $this->actingAs($this->user)->delete("/classes/{$class->ulid}");

        $response->assertRedirect();
        $this->assertNotSame(403, $response->getStatusCode());
        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertSame('error', $this->toast()['type']);
        $this->assertStringContainsString('Arquive', $this->toast()['message']);
        $this->assertNotNull(SchoolClass::withoutGlobalScopes()->find($class->id));

        $this->actingAs($this->user)
            ->post("/classes/{$class->ulid}/archive")
            ->assertRedirect();

        $this->assertNotNull($class->refresh()->archived_at);
    }

    #[Test]
    public function an_archived_class_disappears_from_the_index_and_appears_in_the_archived_list(): void
    {
        $class = $this->createClass();
        $this->actingAs($this->user)->post("/classes/{$class->ulid}/archive");

        $this->actingAs($this->user)->get('/classes')
            ->assertInertia(fn ($page) => $page->component('classes/Index')->has('classes', 0));

        $this->actingAs($this->user)->get('/classes/archived')
            ->assertInertia(fn ($page) => $page
                ->component('classes/Index')
                ->where('viewingArchived', true)
                ->has('classes', 1)
                ->where('classes.0.ulid', $class->ulid));
    }

    // ------------------------------------------------------- restaurar

    #[Test]
    public function restoring_an_archived_class_nulls_archived_at_and_leaves_its_data_untouched(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Miguel Santos');
        $this->actingAs($this->user)->post("/classes/{$class->ulid}/archive");
        $this->assertNotNull($class->refresh()->archived_at);

        $this->actingAs($this->user)
            ->delete("/classes/{$class->ulid}/archive")
            ->assertRedirect();

        $this->assertNull($class->refresh()->archived_at);
        $this->assertNotNull(Enrollment::withoutGlobalScopes()->find($enrollment->id));

        $this->actingAs($this->user)->get('/classes')
            ->assertInertia(fn ($page) => $page->has('classes', 1));
        $this->actingAs($this->user)->get('/classes/archived')
            ->assertInertia(fn ($page) => $page->has('classes', 0));
    }

    // ------------------------------------------------------- elegibilidade

    #[Test]
    public function eligibility_is_computed_from_the_academic_years_end_date_and_names_it_in_the_refusal(): void
    {
        $class = $this->createClassEndingOn('2027-06-30');
        $this->actingAs($this->user)->post("/classes/{$class->ulid}/archive");
        $class->refresh();

        // exatamente ends_on + 3 anos — nunca created_at nem archived_at.
        $this->assertSame('2030-06-30', $class->eligibleForPermanentDeletionAt()?->toDateString());
        $this->assertFalse($class->isEligibleForPermanentDeletion());

        $response = $this->actingAs($this->user)->delete("/classes/{$class->ulid}");

        $response->assertRedirect();
        $this->assertSame('error', $this->toast()['type']);
        $this->assertStringContainsString('30/06/2030', $this->toast()['message']);
        $this->assertNotNull(SchoolClass::withoutGlobalScopes()->find($class->id));
    }

    #[Test]
    public function a_time_eligible_archived_class_with_no_history_is_actually_deleted(): void
    {
        // Bem para lá dos três anos: acabou em 2022, e "hoje" no ambiente de
        // teste está muito depois disso.
        $class = $this->createClassEndingOn('2022-06-30');
        $this->actingAs($this->user)->post("/classes/{$class->ulid}/archive");
        $class->refresh();

        $this->assertTrue($class->isEligibleForPermanentDeletion());

        $response = $this->actingAs($this->user)->delete("/classes/{$class->ulid}");

        $response->assertRedirect('/classes');
        $this->assertNull(SchoolClass::withoutGlobalScopes()->find($class->id));
    }

    #[Test]
    public function a_time_eligible_archived_class_with_history_is_refused_and_everything_is_preserved(): void
    {
        $class = $this->createClassEndingOn('2022-06-30');
        $this->actingAs($this->user)->post("/classes/{$class->ulid}/archive");
        $class->refresh();
        $this->assertTrue($class->isEligibleForPermanentDeletion());

        $instrument = app(CurrentOrganization::class)->runFor(
            $this->user->personalOrganization(),
            fn (): Instrument => Instrument::factory()
                ->recycle($this->user->personalOrganization())
                ->for($class, 'schoolClass')
                ->create(),
        );

        $response = $this->actingAs($this->user)->delete("/classes/{$class->ulid}");

        $response->assertRedirect();
        $this->assertNotSame(500, $response->getStatusCode());
        $toast = $this->toast();
        $this->assertSame('error', $toast['type']);
        $this->assertStringContainsString('elementos de avaliação', $toast['message']);
        $this->assertStringContainsString('preservados', $toast['message']);
        $this->assertStringContainsString($class->label, $toast['message']);

        $this->assertNotNull(SchoolClass::withoutGlobalScopes()->find($class->id));
        $this->assertNotNull(Instrument::withoutGlobalScopes()->find($instrument->id));
    }

    // ------------------------------------------------------- isolamento entre turmas

    #[Test]
    public function lifecycle_actions_on_one_class_never_touch_another_classs_enrollment_or_the_shared_student(): void
    {
        $classA = $this->createClass();
        $classB = $this->createClass();
        $enrollmentA = $this->enroll($classA, 'Aluno Partilhado');

        // O MESMO ALUNO, noutra turma — construído diretamente porque o fluxo
        // normal de inscrição cria sempre um Student novo; aqui o que importa
        // é confirmar que apagar/arquivar a turma A nunca mexe numa linha que
        // pertence à turma B, mesmo quando aponta para o mesmo aluno.
        $enrollmentB = app(CurrentOrganization::class)->runFor(
            $this->user->personalOrganization(),
            fn (): Enrollment => Enrollment::create([
                'class_id' => $classB->id,
                'student_id' => $enrollmentA->student_id,
                'enrolled_on' => $classB->academicYear->starts_on->toDateString(),
                'status' => EnrollmentStatus::Active,
                'is_late_entry' => false,
            ]),
        );

        $this->actingAs($this->user)
            ->post("/classes/{$classA->ulid}/archive")
            ->assertRedirect();

        $this->assertNotNull(Enrollment::withoutGlobalScopes()->find($enrollmentB->id));
        $this->assertSame($classB->id, $enrollmentB->fresh()->class_id);
        $this->assertNotNull(Student::withoutGlobalScopes()->find($enrollmentA->student_id));
    }

    // ------------------------------------------------------- autorização

    #[Test]
    public function a_non_owner_teacher_can_archive_and_restore_but_not_permanently_delete(): void
    {
        $class = $this->createClass();
        $organization = $this->user->personalOrganization();
        $colleague = User::factory()->create();
        $organization->members()->attach($colleague, ['joined_at' => now()]);
        $class->teachers()->attach($colleague->id, ['role' => 'co_teacher']);

        $this->withSession(['organization_id' => $organization->id])
            ->actingAs($colleague)
            ->post("/classes/{$class->ulid}/archive")
            ->assertRedirect();
        $this->assertNotNull($class->refresh()->archived_at);

        $this->withSession(['organization_id' => $organization->id])
            ->actingAs($colleague)
            ->delete("/classes/{$class->ulid}/archive")
            ->assertRedirect();
        $this->assertNull($class->refresh()->archived_at);

        // Re-arquivar para poder testar a recusa de eliminação.
        $this->actingAs($this->user)->post("/classes/{$class->ulid}/archive");

        $this->withSession(['organization_id' => $organization->id])
            ->actingAs($colleague)
            ->delete("/classes/{$class->ulid}")
            ->assertForbidden();
        $this->assertNotNull(SchoolClass::withoutGlobalScopes()->find($class->id));
    }

    #[Test]
    public function a_teacher_not_assigned_to_the_class_is_denied_all_three_actions(): void
    {
        $class = $this->createClass();
        $organization = $this->user->personalOrganization();
        $colleague = User::factory()->create();
        $organization->members()->attach($colleague, ['joined_at' => now()]);

        $this->withSession(['organization_id' => $organization->id])
            ->actingAs($colleague)
            ->post("/classes/{$class->ulid}/archive")
            ->assertForbidden();

        $this->withSession(['organization_id' => $organization->id])
            ->actingAs($colleague)
            ->delete("/classes/{$class->ulid}/archive")
            ->assertForbidden();

        $this->withSession(['organization_id' => $organization->id])
            ->actingAs($colleague)
            ->delete("/classes/{$class->ulid}")
            ->assertForbidden();

        $this->assertNull($class->refresh()->archived_at);
    }

    #[Test]
    public function a_class_from_another_organization_returns_not_found_for_all_three_actions(): void
    {
        $class = $this->createClass();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->post("/classes/{$class->ulid}/archive")->assertNotFound();
        $this->actingAs($stranger)->delete("/classes/{$class->ulid}/archive")->assertNotFound();
        $this->actingAs($stranger)->delete("/classes/{$class->ulid}")->assertNotFound();

        $this->assertNull($class->refresh()->archived_at);
    }

    // ------------------------------------------------------- o que a lista conta

    #[Test]
    public function each_list_is_scoped_to_the_teachers_own_organization_and_archive_state(): void
    {
        $activeClass = $this->createClass();
        $archivedClass = $this->createClass();
        $this->actingAs($this->user)->post("/classes/{$archivedClass->ulid}/archive");

        // Uma turma arquivada de OUTRA organização não pode aparecer aqui.
        $strangerOwner = User::factory()->create();
        $strangerClass = $this->createClassEndingOn('2027-06-30', $strangerOwner);
        $this->actingAs($strangerOwner)->post("/classes/{$strangerClass->ulid}/archive");

        $this->actingAs($this->user)->get('/classes')
            ->assertInertia(fn ($page) => $page
                ->has('classes', 1)
                ->where('classes.0.ulid', $activeClass->ulid));

        $this->actingAs($this->user)->get('/classes/archived')
            ->assertInertia(fn ($page) => $page
                ->has('classes', 1)
                ->where('classes.0.ulid', $archivedClass->ulid));
    }

    #[Test]
    public function every_foreign_key_pointing_at_classes_is_accounted_for(): void
    {
        $connection = DB::connection();
        $schema = $connection->getDriverName() === 'mysql' ? $connection->getDatabaseName() : null;

        // AS DUAS LISTAS, E A SOMA TEM DE DAR TUDO: `RELATIONS` é o que
        // BLOQUEIA a eliminação definitiva; a lista de cascata é o que a
        // base de dados já limpa sozinha, sem nada aqui para decidir.
        $known = (fn (): array => array_keys(static::RELATIONS))
            ->call(new SchoolClassHistory);

        $cascadeAllowlist = ['class_teachers', 'calendar_event_school_class', 'correction_imports'];

        $pointingAtClasses = [];

        foreach (Schema::getTables($schema) as $table) {
            foreach (Schema::getForeignKeys($table['name']) as $foreignKey) {
                if (strtolower((string) $foreignKey['foreign_table']) === 'classes') {
                    $pointingAtClasses[] = $table['name'];
                }
            }
        }

        $known = array_merge($known, $cascadeAllowlist);

        sort($pointingAtClasses);
        sort($known);

        $this->assertSame(
            $pointingAtClasses,
            array_values(array_unique($known)),
            'Há uma tabela com chave estrangeira para `classes` que SchoolClassHistory não conhece nem a lista de cascata explica.',
        );
    }
}
