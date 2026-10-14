<?php

namespace Tests\Feature\Classes;

use App\Models\AssessmentProfile;
use App\Models\AssessmentProfileVersion;
use App\Models\AuditEvent;
use App\Models\ClassProfileMigration;
use App\Models\Organization;
use App\Models\ProfileVersionStatus;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MigratesPercentageClassToLevels;
use Tests\TestCase;

/**
 * 0.166.0 — a página da turma diz que perfil, versão e escala a turma usa, e
 * oferece «Rever e aplicar alterações» quando o perfil já tem uma versão ativa
 * mais recente. O botão só leva à pré-visualização existente; quem confirma a
 * migração continua a ser a rota de sempre. Em acesso técnico (ADR-0014) a
 * migração fica atribuída ao administrador, em nome do professor.
 */
class ClassProfileUpdateTest extends TestCase
{
    use MigratesPercentageClassToLevels, RefreshDatabase;

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seedTeacherAndDemo();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true, 'is_support_technician' => true])->save();

        return $admin;
    }

    /**
     * @return array<string, mixed>
     */
    private function technicalAccess(User $admin): array
    {
        return ['impersonator_id' => $admin->id, 'support_access_id' => 'sa-test-1'];
    }

    /**
     * Todas as colunas da decisão publicada de 65.
     *
     * @return array<string, mixed>
     */
    private function publishedRow(SchoolClass $class): array
    {
        return $this->inTenant(function () use ($class): array {
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();

            return $this->classificationOf('Carolina Nunes', $period->id)->getAttributes();
        });
    }

    private function classOnDatabase(SchoolClass $class): SchoolClass
    {
        return SchoolClass::withoutGlobalScopes()->findOrFail($class->id);
    }

    #[Test]
    public function the_class_page_names_the_profile_version_and_scale_and_offers_the_review(): void
    {
        ['class' => $class] = $this->percentageClassWithProposals();

        $this->actingAs($this->teacher)->get("/classes/{$class->ulid}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('schoolClass.profile_name', $class->profileVersion->profile->name)
                ->where('schoolClass.profile.name', $class->profileVersion->profile->name)
                ->where('schoolClass.profile.version_number', $this->percentageVersion->version_number)
                ->where('schoolClass.profile.scale_name', 'Percentagem (0 a 100)')
                ->where('schoolClass.profile_update.version_number', $this->levelVersion->version_number)
                ->where('schoolClass.profile_update.scale_name', 'Escala 1 a 5')
                ->where('schoolClass.profile_update.review_url', "/classes/{$class->ulid}/profile-migration?to={$this->levelVersion->ulid}"));
    }

    #[Test]
    public function there_is_no_offer_when_the_class_is_already_on_the_current_version(): void
    {
        ['class' => $class] = $this->percentageClassWithProposals();
        $this->migrateToTheLevelVersion($class);

        $this->actingAs($this->teacher)->get("/classes/{$class->ulid}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('schoolClass.profile.version_number', $this->levelVersion->version_number)
                ->where('schoolClass.profile.scale_name', 'Escala 1 a 5')
                ->where('schoolClass.profile_update', null));
    }

    #[Test]
    public function an_archived_class_shows_its_version_but_is_never_offered_the_update(): void
    {
        ['class' => $class] = $this->percentageClassWithProposals();
        $this->inTenant(fn () => SchoolClass::whereKey($class->id)->firstOrFail()->forceFill(['archived_at' => now()])->save());

        // História: a versão com que a turma foi avaliada continua visível, e o
        // ecrã não convida a mudá-la.
        $this->actingAs($this->teacher)->get("/classes/{$class->ulid}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('schoolClass.profile.version_number', $this->percentageVersion->version_number)
                ->where('schoolClass.profile.scale_name', 'Percentagem (0 a 100)')
                ->where('schoolClass.profile_update', null));
    }

    #[Test]
    public function there_is_no_offer_when_the_profile_has_no_current_version_or_it_is_not_active(): void
    {
        ['class' => $class] = $this->percentageClassWithProposals();

        $profileId = $this->levelVersion->assessment_profile_id;
        $this->inTenant(fn () => AssessmentProfile::query()->whereKey($profileId)->update(['current_version_id' => null]));

        $this->actingAs($this->teacher)->get("/classes/{$class->ulid}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('schoolClass.profile_update', null));

        $this->inTenant(function () use ($profileId): void {
            AssessmentProfile::query()->whereKey($profileId)->update(['current_version_id' => $this->levelVersion->id]);
            AssessmentProfileVersion::query()->whereKey($this->levelVersion->id)->update(['status' => ProfileVersionStatus::Superseded->value]);
        });

        $this->actingAs($this->teacher)->get("/classes/{$class->ulid}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('schoolClass.profile_update', null));
    }

    #[Test]
    public function there_is_no_offer_for_a_class_without_a_profile(): void
    {
        $class = $this->demoClass();
        $this->inTenant(fn () => SchoolClass::query()->whereKey($class->id)->update(['assessment_profile_version_id' => null]));

        $this->actingAs($this->teacher)->get("/classes/{$class->ulid}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('schoolClass.profile', null)
                ->where('schoolClass.profile_name', null)
                ->where('schoolClass.profile_update', null));
    }

    #[Test]
    public function someone_who_does_not_teach_the_class_gets_neither_the_page_nor_the_review(): void
    {
        ['class' => $class] = $this->percentageClassWithProposals();

        $colleague = User::factory()->create();
        $this->inTenant(fn () => $this->teacher->personalOrganization()->members()->attach($colleague, ['joined_at' => now()]));

        $this->actingAs($colleague)->get("/classes/{$class->ulid}")->assertNotFound();
        $this->actingAs($colleague)->get("/classes/{$class->ulid}/profile-migration?to={$this->levelVersion->ulid}")->assertNotFound();
        $this->actingAs($colleague)
            ->post("/classes/{$class->ulid}/profile-migration", ['to_version' => $this->levelVersion->ulid, 'reason' => 'Sem autorização.'])
            ->assertNotFound();
        $this->assertSame(0, ClassProfileMigration::withoutGlobalScopes()->count());
    }

    #[Test]
    public function another_organization_cannot_reach_the_class_the_review_or_the_migration(): void
    {
        ['class' => $class] = $this->percentageClassWithProposals();
        $before = $this->publishedRow($class);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->get("/classes/{$class->ulid}")->assertNotFound();
        $this->actingAs($intruder)->get("/classes/{$class->ulid}/profile-migration?to={$this->levelVersion->ulid}")->assertNotFound();
        $this->actingAs($intruder)
            ->post("/classes/{$class->ulid}/profile-migration", ['to_version' => $this->levelVersion->ulid, 'reason' => 'Intrusão.'])
            ->assertNotFound();

        $this->assertSame(0, ClassProfileMigration::withoutGlobalScopes()->count());
        $this->assertSame($this->percentageVersion->id, $this->classOnDatabase($class)->assessment_profile_version_id);
        $this->assertEquals($before, $this->publishedRow($class));
    }

    #[Test]
    public function a_version_that_is_not_active_or_belongs_to_another_organization_is_refused(): void
    {
        ['class' => $class] = $this->percentageClassWithProposals();
        $this->inTenant(fn () => AssessmentProfileVersion::query()->whereKey($this->levelVersion->id)
            ->update(['status' => ProfileVersionStatus::Superseded->value]));

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/profile-migration?to={$this->levelVersion->ulid}")
            ->assertRedirect("/classes/{$class->ulid}");
        $this->actingAs($this->teacher)
            ->post("/classes/{$class->ulid}/profile-migration", ['to_version' => $this->levelVersion->ulid, 'reason' => 'Versão já não ativa.'])
            ->assertSessionHasErrors('reason');

        $organization = Organization::factory()->create();
        $strangerVersion = AssessmentProfileVersion::factory()->recycle($organization)->create([
            'status' => ProfileVersionStatus::Active,
            'scale_id' => $this->levelVersion->scale_id,
        ]);

        $this->actingAs($this->teacher)
            ->post("/classes/{$class->ulid}/profile-migration", ['to_version' => $strangerVersion->ulid, 'reason' => 'Versão alheia.'])
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, ClassProfileMigration::withoutGlobalScopes()->count());
        $this->assertSame($this->percentageVersion->id, $this->classOnDatabase($class)->assessment_profile_version_id);
    }

    #[Test]
    public function the_teacher_journey_reviews_confirms_and_the_page_shows_the_new_version(): void
    {
        ['class' => $class] = $this->percentageClassWithProposals();
        $before = $this->publishedRow($class);

        $reviewUrl = null;
        $this->actingAs($this->teacher)->get("/classes/{$class->ulid}")
            ->assertInertia(function (Assert $page) use (&$reviewUrl): void {
                $reviewUrl = $page->toArray()['props']['schoolClass']['profile_update']['review_url'];
            });

        $this->get($reviewUrl)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('classes/ProfileMigration'));

        $this->post("/classes/{$class->ulid}/profile-migration", ['to_version' => $this->levelVersion->ulid, 'reason' => 'Passa a escala de 1 a 5.'])
            ->assertRedirect("/classes/{$class->ulid}");

        $migration = $this->inTenant(fn () => ClassProfileMigration::query()->sole());
        $this->assertSame($this->teacher->id, $migration->confirmed_by);
        $this->assertNull($migration->on_behalf_of_user_id);

        $event = $this->inTenant(fn () => AuditEvent::query()->where('event', 'class.profile_migrated')->sole());
        $this->assertSame($this->teacher->id, $event->causer_id);
        $this->assertArrayNotHasKey('acting_as_user_id', $event->properties);
        $this->assertArrayNotHasKey('technical_access', $event->properties);

        $this->get("/classes/{$class->ulid}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('schoolClass.profile.version_number', $this->levelVersion->version_number)
                ->where('schoolClass.profile.scale_name', 'Escala 1 a 5')
                ->where('schoolClass.profile_update', null));

        $this->assertEquals($before, $this->publishedRow($class));
    }

    #[Test]
    public function the_success_message_says_what_was_applied(): void
    {
        ['class' => $class] = $this->percentageClassWithProposals();

        $response = $this->actingAs($this->teacher)
            ->post("/classes/{$class->ulid}/profile-migration", ['to_version' => $this->levelVersion->ulid, 'reason' => 'Passa a escala de 1 a 5.'])
            ->assertRedirect("/classes/{$class->ulid}");

        $affected = $this->inTenant(fn () => ClassProfileMigration::query()->sole()->affected_enrollment_count);
        $name = $this->levelVersion->profile->name;
        $students = $affected === 1 ? 'aluno' : 'alunos';
        $expected = "Turma atualizada para «{$name}», versão {$this->levelVersion->version_number} (Escala 1 a 5): {$affected} {$students} com a proposta alterada.";

        $response->assertSessionHas('inertia.flash_data', fn ($flash) => $flash['toast']['type'] === 'success' && $flash['toast']['message'] === $expected);
    }

    #[Test]
    public function the_same_journey_under_technical_access_is_attributed_to_the_administrator(): void
    {
        ['class' => $class] = $this->percentageClassWithProposals();
        $before = $this->publishedRow($class);
        $admin = $this->admin();
        $session = $this->technicalAccess($admin);

        $this->actingAs($this->teacher)->withSession($session)
            ->get("/classes/{$class->ulid}/profile-migration?to={$this->levelVersion->ulid}")->assertOk();

        $this->actingAs($this->teacher)->withSession($session)
            ->post("/classes/{$class->ulid}/profile-migration", ['to_version' => $this->levelVersion->ulid, 'reason' => 'Apoio técnico a pedido.'])
            ->assertRedirect("/classes/{$class->ulid}");

        $migration = $this->inTenant(fn () => ClassProfileMigration::query()->sole());
        $this->assertSame($admin->id, $migration->confirmed_by);
        $this->assertSame($this->teacher->id, $migration->on_behalf_of_user_id);
        // A titularidade não muda: a linha é da organização da turma.
        $this->assertSame($this->teacher->personalOrganization()->id, $migration->organization_id);

        $event = $this->inTenant(fn () => AuditEvent::query()->where('event', 'class.profile_migrated')->sole());
        $this->assertSame($admin->id, $event->causer_id);
        $this->assertSame($this->teacher->id, $event->properties['acting_as_user_id']);
        $this->assertTrue($event->properties['technical_access']);

        $this->assertEquals($before, $this->publishedRow($class));

        // A atividade do professor mostra o que foi feito em seu nome, como
        // suporte técnico — e em lado nenhum o nome ou o email do administrador.
        $response = $this->actingAs($this->teacher)->get('/activity')->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('events.0.event', 'class.profile_migrated')
            ->where('events.0.causer', 'Suporte técnico Lapispro')
            ->where('events.0.technical_access', true));

        $payload = (string) json_encode($response->viewData('page'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString($admin->name, $payload);
        $this->assertStringNotContainsString($admin->email, $payload);
        $this->assertStringNotContainsString('sa-test-1', $payload);
    }

    #[Test]
    public function another_organizations_activity_never_shows_the_technical_access(): void
    {
        ['class' => $class] = $this->percentageClassWithProposals();
        $admin = $this->admin();

        $this->actingAs($this->teacher)->withSession($this->technicalAccess($admin))
            ->post("/classes/{$class->ulid}/profile-migration", ['to_version' => $this->levelVersion->ulid, 'reason' => 'Apoio técnico a pedido.'])
            ->assertRedirect();

        $other = User::factory()->create();
        $this->actingAs($other)->get('/activity')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('events', 0));
    }

    #[Test]
    public function an_old_event_without_the_mark_is_presented_as_before(): void
    {
        ['class' => $class] = $this->percentageClassWithProposals();
        $this->migrateToTheLevelVersion($class);

        $this->actingAs($this->teacher)->get('/activity')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('events', fn ($events) => collect($events)->contains(fn ($event) => $event['event'] === 'class.profile_migrated'
                    && $event['causer'] === $this->teacher->name
                    && $event['technical_access'] === false)));
    }
}
