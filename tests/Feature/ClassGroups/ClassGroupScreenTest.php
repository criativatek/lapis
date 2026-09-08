<?php

namespace Tests\Feature\ClassGroups;

use App\Models\ClassGroup;
use App\Models\ClassGroupMembership;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Models\User;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;

/**
 * A SECÇÃO «GRUPOS» DO ECRÃ DA TURMA, pela porta por onde o professor entra:
 * as rotas.
 */
class ClassGroupScreenTest extends ClassGroupsTestCase
{
    #[Test]
    public function a_teacher_creates_renames_and_orders_groups_with_free_labels(): void
    {
        $class = $this->schoolClassFor($this->teacher);

        foreach (['PL1', 'B', 'Grupo da manhã'] as $label) {
            $this->asTeacher()
                ->post("/classes/{$class->ulid}/groups", ['label' => $label])
                ->assertRedirect();
        }

        $groups = $this->inTenant($this->organization, fn () => $class->classGroups()->get());

        $this->assertSame(['PL1', 'B', 'Grupo da manhã'], $groups->pluck('label')->all());
        $this->assertSame([1, 2, 3], $groups->pluck('position')->all());

        $this->asTeacher()
            ->put("/classes/{$class->ulid}/groups/{$groups[0]->ulid}", ['label' => 'PL-A'])
            ->assertRedirect();

        $this->asTeacher()
            ->put("/classes/{$class->ulid}/groups/order", [
                'ulids' => [$groups[2]->ulid, $groups[1]->ulid, $groups[0]->ulid],
            ])
            ->assertRedirect();

        $reordered = $this->inTenant($this->organization, fn () => $class->classGroups()->get());

        $this->assertSame(['Grupo da manhã', 'B', 'PL-A'], $reordered->pluck('label')->all());
    }

    #[Test]
    public function two_groups_of_the_same_class_cannot_share_a_label(): void
    {
        $class = $this->schoolClassFor($this->teacher);

        $this->asTeacher()->post("/classes/{$class->ulid}/groups", ['label' => 'T1']);

        $this->asTeacher()
            ->post("/classes/{$class->ulid}/groups", ['label' => 'T1'])
            ->assertSessionHasErrors('label');

        $this->assertSame(1, $this->inTenant($this->organization, fn () => ClassGroup::query()->count()));
    }

    #[Test]
    public function two_different_classes_may_each_have_their_own_t1(): void
    {
        $first = $this->schoolClassFor($this->teacher);
        $second = $this->schoolClassFor($this->teacher);

        $this->asTeacher()->post("/classes/{$first->ulid}/groups", ['label' => 'T1'])->assertRedirect();
        $this->asTeacher()->post("/classes/{$second->ulid}/groups", ['label' => 'T1'])->assertRedirect();

        $this->assertSame(2, $this->inTenant($this->organization, fn () => ClassGroup::query()->count()));
    }

    #[Test]
    public function the_bulk_assignment_asks_for_no_dates_and_leaves_the_rest_without_a_group(): void
    {
        $class = $this->schoolClassFor($this->teacher);
        $enrollments = $this->enroll($class, 4);
        $group = $this->group($class, 'T1');

        $this->asTeacher()
            ->post("/classes/{$class->ulid}/groups/assignments", [
                'assignments' => [
                    ['enrollment_id' => $enrollments[0]->id, 'class_group_id' => $group->id],
                    ['enrollment_id' => $enrollments[1]->id, 'class_group_id' => $group->id],
                ],
            ])
            ->assertRedirect();

        $memberships = $this->inTenant($this->organization, fn () => ClassGroupMembership::query()->get());

        $this->assertCount(2, $memberships);
        $this->assertSame(
            [self::YEAR_STARTS_ON, self::YEAR_STARTS_ON],
            $memberships->map(fn ($membership) => $membership->effective_from->toDateString())->all(),
        );

        // «Sem grupo» é o estado dos outros dois, e é legítimo.
        $response = $this->asTeacher()->get("/classes/{$class->ulid}");
        $students = $response->viewData('page')['props']['students'];
        $withoutGroup = array_filter($students, fn (array $student): bool => $student['class_group_id'] === null);

        $this->assertCount(2, $withoutGroup);
    }

    #[Test]
    public function the_class_page_carries_the_groups_and_their_current_size(): void
    {
        $class = $this->schoolClassFor($this->teacher);
        $enrollments = $this->enroll($class, 3);
        $group = $this->group($class, 'T1');

        $this->asTeacher()->post("/classes/{$class->ulid}/groups/assignments", [
            'assignments' => [['enrollment_id' => $enrollments[0]->id, 'class_group_id' => $group->id]],
        ]);

        $props = $this->asTeacher()->get("/classes/{$class->ulid}")->viewData('page')['props'];

        $this->assertCount(1, $props['classGroups']);
        $this->assertSame('T1', $props['classGroups'][0]['label']);
        $this->assertSame(1, $props['classGroups'][0]['members_count']);
        $this->assertFalse($props['classGroups'][0]['archived']);
    }

    #[Test]
    public function a_group_with_no_history_is_deleted_and_one_with_history_is_not(): void
    {
        $class = $this->schoolClassFor($this->teacher);
        [$enrollment] = $this->enroll($class, 1);
        $empty = $this->group($class, 'Vazio');
        $used = $this->group($class, 'T1');

        $this->asTeacher()->post("/classes/{$class->ulid}/groups/assignments", [
            'assignments' => [['enrollment_id' => $enrollment->id, 'class_group_id' => $used->id]],
        ]);

        $this->asTeacher()
            ->delete("/classes/{$class->ulid}/groups/{$empty->ulid}")
            ->assertRedirect();
        $this->asTeacher()
            ->delete("/classes/{$class->ulid}/groups/{$used->ulid}")
            ->assertRedirect();

        $remaining = $this->inTenant($this->organization, fn () => ClassGroup::query()->pluck('label')->all());

        $this->assertSame(['T1'], $remaining);
    }

    #[Test]
    public function archiving_is_blocked_while_the_schedule_still_uses_the_group(): void
    {
        $class = $this->schoolClassFor($this->teacher);
        $group = $this->group($class, 'T1');

        $this->inTenant($this->organization, fn () => RecurringLessonSlot::create([
            'class_id' => $class->id,
            'class_group_id' => $group->id,
            'day_of_week' => 5,
            'starts_at' => '08:30',
            'ends_at' => '09:20',
            'starts_on' => self::YEAR_STARTS_ON,
            'ends_on' => null,
        ]));

        $this->asTeacher()
            ->post("/classes/{$class->ulid}/groups/{$group->ulid}/archive")
            ->assertRedirect();

        $this->assertNull(
            $this->inTenant($this->organization, fn () => $group->fresh()->archived_at),
            'O grupo não podia ter sido arquivado com tempos do horário ainda em vigor.',
        );
    }

    /**
     * O ecrã lê a composição PELO ANO LETIVO, e não pelo relógio.
     *
     * Encontrado a validar isto no browser: a 9 de setembro, numa turma cujo
     * ano começa a 14, o professor distribuía os trinta alunos e a página
     * respondia-lhe «T1: 0 alunos» com todos debaixo de «Sem grupo» — porque as
     * pertenças começam no primeiro dia do ano e esse dia ainda não tinha
     * chegado. E «Distribuir alunos» voltava a oferecê-los, para o servidor os
     * recusar a seguir com «já pertence a um grupo».
     */
    #[Test]
    public function the_page_reads_the_composition_inside_the_academic_year_even_before_it_starts(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-20 09:00:00', self::TIMEZONE));

        $class = $this->schoolClassFor($this->teacher);
        [$enrollment] = $this->enroll($class, 1);
        $group = $this->group($class, 'T1');

        $this->asTeacher()->post("/classes/{$class->ulid}/groups/assignments", [
            'assignments' => [['enrollment_id' => $enrollment->id, 'class_group_id' => $group->id]],
        ])->assertRedirect();

        $props = $this->asTeacher()->get("/classes/{$class->ulid}")->viewData('page')['props'];

        $this->assertSame(1, $props['classGroups'][0]['members_count']);
        $this->assertSame($group->id, $props['students'][0]['class_group_id']);
        // E a data que os diálogos oferecem é a do início do ano, não «hoje» —
        // «hoje» seria recusado por cair fora do ano letivo.
        $this->assertSame(self::YEAR_STARTS_ON, $props['classGroupsDefaultDate']);
    }

    /**
     * O MESMO BECO, POR ALUNO: quem entra em novembro não pode aparecer «Sem
     * grupo» de setembro a novembro.
     *
     * A pertença dele começa no dia em que entrou (§ initialEffectiveFrom), e
     * lida pelo dia de hoje ele ficava dois meses debaixo de «Sem grupo» —
     * com «Distribuir alunos» a voltar a oferecê-lo e o servidor a recusá-lo
     * a seguir. O ecrã mostra o grupo em que ele entra, e a data a partir da
     * qual isso vale.
     */
    #[Test]
    public function a_late_entry_student_shows_the_group_they_are_about_to_join(): void
    {
        $class = $this->schoolClassFor($this->teacher);
        [$early] = $this->enroll($class, 1);
        [$late] = $this->enroll($class, 1, '2026-11-03');
        $group = $this->group($class, 'T1');

        $this->asTeacher()->post("/classes/{$class->ulid}/groups/assignments", [
            'assignments' => [
                ['enrollment_id' => $early->id, 'class_group_id' => $group->id],
                ['enrollment_id' => $late->id, 'class_group_id' => $group->id],
            ],
        ])->assertRedirect();

        $props = $this->asTeacher()->get("/classes/{$class->ulid}")->viewData('page')['props'];
        $students = collect($props['students'])->keyBy('id');

        $this->assertSame($group->id, $students[$late->id]['class_group_id']);
        $this->assertSame('2026-11-03', $students[$late->id]['class_group_since']);
        $this->assertSame(self::YEAR_STARTS_ON, $students[$early->id]['class_group_since']);
        $this->assertSame(2, $props['classGroups'][0]['members_count']);
    }

    #[Test]
    public function a_teacher_who_does_not_teach_the_class_gets_nowhere(): void
    {
        $class = $this->schoolClassFor($this->teacher);
        $stranger = User::factory()->create();
        $this->organization->members()->attach($stranger, ['joined_at' => now()]);

        $this->actingAs($stranger)
            ->withSession(['organization_id' => $this->organization->id])
            ->post("/classes/{$class->ulid}/groups", ['label' => 'T1'])
            ->assertForbidden();

        $this->assertSame(0, $this->inTenant($this->organization, fn () => ClassGroup::query()->count()));
    }

    #[Test]
    public function a_class_from_another_organization_is_not_found(): void
    {
        $otherTeacher = User::factory()->create();
        $otherOrganization = $otherTeacher->personalOrganization();
        $otherClass = $this->schoolClassFor($otherTeacher, $otherOrganization);

        $this->asTeacher()
            ->post("/classes/{$otherClass->ulid}/groups", ['label' => 'T1'])
            ->assertNotFound();
    }

    #[Test]
    public function a_group_from_another_class_cannot_be_reached_through_this_one(): void
    {
        $class = $this->schoolClassFor($this->teacher);
        $other = $this->schoolClassFor($this->teacher);
        $foreignGroup = $this->group($other, 'T1');

        $this->asTeacher()
            ->put("/classes/{$class->ulid}/groups/{$foreignGroup->ulid}", ['label' => 'T9'])
            ->assertNotFound();
    }

    protected function group(SchoolClass $class, string $label): ClassGroup
    {
        return $this->inTenant(
            $this->organization,
            fn (): ClassGroup => ClassGroup::create([
                'class_id' => $class->id,
                'label' => $label,
                'position' => 0,
            ]),
        );
    }
}
