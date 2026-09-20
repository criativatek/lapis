<?php

namespace Tests\Feature\SubjectParticipation;

use App\Models\AcademicYear;
use App\Models\AuditEvent;
use App\Models\Enrollment;
use App\Models\ExternalSubjectResult;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\SubjectParticipation;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A frequência de uma disciplina e os resultados externos, pela porta por
 * onde o professor entra: as rotas de `SubjectParticipationController`.
 *
 * As regras de negócio (data dentro do ano letivo, janelas que não se
 * sobrepõem) já têm cobertura própria nas ações
 * (`App\Actions\SubjectParticipation`) — o que este ficheiro verifica é a
 * fronteira HTTP: quem pode chamar cada rota, e que a inscrição/resultado é
 * sempre resolvido ATRAVÉS da turma.
 */
class SubjectParticipationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected const FROZEN_NOW = '2026-11-10 10:00:00';

    protected const YEAR_STARTS_ON = '2026-09-01';

    protected const YEAR_ENDS_ON = '2027-06-30';

    protected Organization $organization;

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::FROZEN_NOW, 'Europe/Lisbon'));

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
        $this->subscribeToPro($this->organization);
    }

    protected function subscribeToPro(Organization $organization): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->delete();
        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->id,
            'plan_id' => Plan::query()->where('key', 'pro')->firstOrFail()->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);
        app(Entitlements::class)->flush();
    }

    protected function inTenant(Organization $organization, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization, $callback);
    }

    protected function asTeacher(?User $user = null): static
    {
        $user ??= $this->teacher;

        return $this->actingAs($user)->withSession(['organization_id' => $this->organization->id]);
    }

    /**
     * @return array{0: SchoolClass, 1: Enrollment}
     */
    protected function classWithEnrollment(?User $teacher = null, string $role = 'owner', ?Organization $organization = null): array
    {
        $teacher ??= $this->teacher;
        $organization ??= $this->organization;

        return $this->inTenant($organization, function () use ($teacher, $role, $organization): array {
            $class = SchoolClass::factory()
                ->recycle($organization)
                ->create([
                    'academic_year_id' => AcademicYear::factory()
                        ->recycle($organization)
                        ->create(['starts_on' => self::YEAR_STARTS_ON, 'ends_on' => self::YEAR_ENDS_ON])
                        ->id,
                ]);
            $class->teachers()->attach($teacher, ['role' => $role]);

            $enrollment = Enrollment::factory()
                ->recycle($organization)
                ->create([
                    'class_id' => $class->id,
                    'student_id' => Student::factory()->recycle($organization)->create()->id,
                    'class_number' => 1,
                    'enrolled_on' => self::YEAR_STARTS_ON,
                    'status' => 'active',
                ]);

            return [$class, $enrollment];
        });
    }

    #[Test]
    public function a_teacher_marks_a_student_as_not_attending_the_subject(): void
    {
        [$class, $enrollment] = $this->classWithEnrollment();

        $this->asTeacher()
            ->post("/classes/{$class->ulid}/participations", [
                'enrollment_id' => $enrollment->id,
                'effective_from' => '2026-11-15',
                'reason' => 'alternative_subject',
                'reason_detail' => 'PLNM',
            ])
            ->assertRedirect();

        $participation = $this->inTenant($this->organization, fn () => SubjectParticipation::query()->sole());

        $this->assertSame($enrollment->id, $participation->enrollment_id);
        $this->assertSame('2026-11-15', $participation->effective_from->toDateString());
        $this->assertNull($participation->effective_until);

        // O aluno continua na turma — só deixou de frequentar a disciplina.
        $this->assertSame('active', $this->inTenant(
            $this->organization,
            fn () => $enrollment->fresh()->status->value,
        ));
    }

    #[Test]
    public function a_teacher_reactivates_a_participation(): void
    {
        [$class, $enrollment] = $this->classWithEnrollment();

        $this->asTeacher()->post("/classes/{$class->ulid}/participations", [
            'enrollment_id' => $enrollment->id,
            'effective_from' => '2026-11-15',
            'reason' => 'alternative_subject',
        ]);

        $this->asTeacher()
            ->post("/classes/{$class->ulid}/participations/reactivations", [
                'enrollment_id' => $enrollment->id,
                'effective_from' => '2027-01-05',
            ])
            ->assertRedirect();

        $participation = $this->inTenant($this->organization, fn () => SubjectParticipation::query()->sole());

        $this->assertSame('2027-01-04', $participation->effective_until->toDateString());
    }

    #[Test]
    public function a_teacher_records_and_deletes_an_external_result(): void
    {
        [$class, $enrollment] = $this->classWithEnrollment();

        $this->asTeacher()
            ->post("/classes/{$class->ulid}/external-results", [
                'enrollment_id' => $enrollment->id,
                'origin' => 'PLNM',
                'recorded_on' => '2026-11-20',
                'level_code' => '4',
            ])
            ->assertRedirect();

        $result = $this->inTenant($this->organization, fn () => ExternalSubjectResult::query()->sole());

        $this->assertSame($enrollment->id, $result->enrollment_id);
        $this->assertSame('4', $result->level_code);

        $this->asTeacher()
            ->delete("/classes/{$class->ulid}/external-results/{$result->ulid}")
            ->assertRedirect();

        $this->assertSame(0, $this->inTenant($this->organization, fn () => ExternalSubjectResult::query()->count()));
    }

    #[Test]
    public function recording_a_result_without_any_value_is_refused_with_a_readable_message(): void
    {
        [$class, $enrollment] = $this->classWithEnrollment();

        $this->asTeacher()
            ->post("/classes/{$class->ulid}/external-results", [
                'enrollment_id' => $enrollment->id,
                'origin' => 'PLNM',
                'recorded_on' => '2026-11-20',
            ])
            ->assertSessionHasErrors('scale_level_id');
    }

    #[Test]
    public function a_scale_level_from_another_organization_is_refused(): void
    {
        [$class, $enrollment] = $this->classWithEnrollment();

        $otherOrganization = Organization::factory()->create();
        $foreignLevel = $this->inTenant($otherOrganization, function () use ($otherOrganization): ScaleLevel {
            $scale = Scale::factory()->create(['organization_id' => $otherOrganization->id]);

            $level = new ScaleLevel([
                'code' => 'X',
                'label' => 'Excelente',
                'sequence' => 1,
            ]);
            $level->scale_id = $scale->id;
            $level->save();

            return $level;
        });

        $this->asTeacher()
            ->post("/classes/{$class->ulid}/external-results", [
                'enrollment_id' => $enrollment->id,
                'origin' => 'PLNM',
                'recorded_on' => '2026-11-20',
                'scale_level_id' => $foreignLevel->id,
            ])
            ->assertSessionHasErrors('scale_level_id');

        $this->assertSame(0, $this->inTenant($this->organization, fn () => ExternalSubjectResult::query()->count()));
    }

    #[Test]
    public function a_scale_level_from_a_different_scale_of_the_same_organization_is_refused(): void
    {
        [$class, $enrollment] = $this->classWithEnrollment();

        // H4.3: MESMA organização, escala DIFERENTE da desta turma —
        // aceitá-lo colocaria o aluno numa banda que a escala desta turma
        // nunca decidiu, mesmo sem cruzar nenhuma fronteira de organização.
        $otherLevel = $this->inTenant($this->organization, function (): ScaleLevel {
            $scale = Scale::factory()->create(['organization_id' => $this->organization->id]);

            $level = new ScaleLevel([
                'code' => 'X',
                'label' => 'Excelente',
                'sequence' => 1,
            ]);
            $level->scale_id = $scale->id;
            $level->save();

            return $level;
        });

        $this->asTeacher()
            ->post("/classes/{$class->ulid}/external-results", [
                'enrollment_id' => $enrollment->id,
                'origin' => 'PLNM',
                'recorded_on' => '2026-11-20',
                'scale_level_id' => $otherLevel->id,
            ])
            ->assertSessionHasErrors('scale_level_id');

        $this->assertSame(0, $this->inTenant($this->organization, fn () => ExternalSubjectResult::query()->count()));
    }

    #[Test]
    public function a_non_teacher_of_the_class_is_forbidden_on_every_route(): void
    {
        [$class, $enrollment] = $this->classWithEnrollment();

        // O estranho não ensina esta turma, mas partilha organização com o
        // professor — a policy é que tem de recusar, não o isolamento.
        $stranger = User::factory()->create();
        $stranger->organizations()->attach($this->organization, ['joined_at' => now()]);

        $asStranger = $this->actingAs($stranger)->withSession(['organization_id' => $this->organization->id]);

        $asStranger->post("/classes/{$class->ulid}/participations", [
            'enrollment_id' => $enrollment->id,
            'effective_from' => '2026-11-15',
            'reason' => 'alternative_subject',
        ])->assertForbidden();

        $asStranger->post("/classes/{$class->ulid}/participations/reactivations", [
            'enrollment_id' => $enrollment->id,
            'effective_from' => '2026-11-15',
        ])->assertForbidden();

        $asStranger->post("/classes/{$class->ulid}/external-results", [
            'enrollment_id' => $enrollment->id,
            'origin' => 'PLNM',
            'recorded_on' => '2026-11-20',
            'level_code' => '4',
        ])->assertForbidden();

        $result = $this->inTenant($this->organization, fn () => ExternalSubjectResult::factory()->create([
            'organization_id' => $this->organization->id,
            'enrollment_id' => $enrollment->id,
        ]));

        $asStranger->delete("/classes/{$class->ulid}/external-results/{$result->ulid}")->assertForbidden();
    }

    #[Test]
    public function an_observer_may_read_but_not_write(): void
    {
        [$class, $enrollment] = $this->classWithEnrollment(role: 'observer');

        $this->asTeacher()
            ->post("/classes/{$class->ulid}/participations", [
                'enrollment_id' => $enrollment->id,
                'effective_from' => '2026-11-15',
                'reason' => 'alternative_subject',
            ])
            ->assertForbidden();

        // Ler continua aberto a um observer — a mesma porta que qualquer
        // outro professor da turma usa para ver o ecrã (§ SchoolClassPolicy::
        // view(), que não distingue papéis). A suite não tem os assets
        // compilados (ver o relatório final), pelo que a página completa
        // não pode ser renderizada aqui — mas a diferença entre 403
        // (SchoolClassPolicy a recusar) e qualquer outra resposta já prova
        // que a leitura não é bloqueada por ser observer.
        $this->assertNotSame(403, $this->asTeacher()->get("/classes/{$class->ulid}")->getStatusCode());
    }

    #[Test]
    public function a_user_from_another_organization_cannot_reach_the_class_at_all(): void
    {
        [$class, $enrollment] = $this->classWithEnrollment();

        $outsider = User::factory()->create();
        $outsiderOrganization = $outsider->personalOrganization();
        $this->subscribeToPro($outsiderOrganization);

        $asOutsider = $this->actingAs($outsider)->withSession(['organization_id' => $outsiderOrganization->id]);

        // A turma de outra organização nem chega a ser resolvida pela rota —
        // o global scope de SchoolClass já a escondeu.
        $asOutsider->post("/classes/{$class->ulid}/participations", [
            'enrollment_id' => $enrollment->id,
            'effective_from' => '2026-11-15',
            'reason' => 'alternative_subject',
        ])->assertNotFound();
    }

    #[Test]
    public function an_enrollment_from_another_class_is_refused_with_404(): void
    {
        [$class] = $this->classWithEnrollment();
        [, $otherEnrollment] = $this->classWithEnrollment();

        $this->asTeacher()
            ->post("/classes/{$class->ulid}/participations", [
                'enrollment_id' => $otherEnrollment->id,
                'effective_from' => '2026-11-15',
                'reason' => 'alternative_subject',
            ])
            ->assertNotFound();
    }

    #[Test]
    public function validation_failures_return_readable_portuguese_messages(): void
    {
        [$class, $enrollment] = $this->classWithEnrollment();

        $response = $this->asTeacher()->post("/classes/{$class->ulid}/participations", [
            'enrollment_id' => $enrollment->id,
            'effective_from' => '',
            'reason' => 'alternative_subject',
        ]);

        $response->assertSessionHasErrors('effective_from');
        $errors = session('errors');
        $this->assertStringContainsString(
            'Indique a partir de quando',
            $errors->first('effective_from'),
        );
    }

    /**
     * M3: A AÇÃO É A DONA DO RASTO DE AUDITORIA — o controlador não pode
     * repeti-lo. Antes desta correção, cada uma destas quatro rotas escrevia
     * DOIS eventos para o MESMO acontecimento: um dentro da Action (que já
     * faz lock/re-check/escrita em transação) e outro, redundante, no
     * controlador — e `destroyResult()` ainda auditava DEPOIS de apagar, o
     * que já não encontraria a linha. Cobrimos as quatro rotas, não só
     * `POST /participations` (L2).
     */
    #[Test]
    public function each_route_writes_exactly_one_audit_event_never_two(): void
    {
        [$class, $enrollment] = $this->classWithEnrollment();

        $this->asTeacher()->post("/classes/{$class->ulid}/participations", [
            'enrollment_id' => $enrollment->id,
            'effective_from' => '2026-11-15',
            'reason' => 'alternative_subject',
            'reason_detail' => 'PLNM',
        ])->assertRedirect();

        $this->assertSame(1, AuditEvent::withoutGlobalScope('organization')
            ->where('event', 'subject_participation.marked_not_attending')
            ->count());

        $this->asTeacher()->post("/classes/{$class->ulid}/participations/reactivations", [
            'enrollment_id' => $enrollment->id,
            'effective_from' => '2027-01-05',
        ])->assertRedirect();

        $this->assertSame(1, AuditEvent::withoutGlobalScope('organization')
            ->where('event', 'subject_participation.reactivated')
            ->count());

        $this->asTeacher()->post("/classes/{$class->ulid}/external-results", [
            'enrollment_id' => $enrollment->id,
            'origin' => 'PLNM',
            'recorded_on' => '2026-11-20',
            'level_code' => '4',
        ])->assertRedirect();

        $this->assertSame(1, AuditEvent::withoutGlobalScope('organization')
            ->where('event', 'external_result.recorded')
            ->count());

        $result = $this->inTenant($this->organization, fn () => ExternalSubjectResult::query()->sole());

        $this->asTeacher()->delete("/classes/{$class->ulid}/external-results/{$result->ulid}")->assertRedirect();

        // A linha já não existe, e o evento tem de ter sido escrito ANTES de
        // ela ser apagada — nunca depois, quando já não haveria nada a ler.
        $this->assertSame(1, AuditEvent::withoutGlobalScope('organization')
            ->where('event', 'external_result.deleted')
            ->count());
        $this->assertSame(0, $this->inTenant($this->organization, fn () => ExternalSubjectResult::query()->count()));
    }
}
