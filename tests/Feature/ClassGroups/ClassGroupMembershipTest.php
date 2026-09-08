<?php

namespace Tests\Feature\ClassGroups;

use App\Actions\ClassGroups\ArchiveClassGroup;
use App\Actions\ClassGroups\AssignClassGroupMemberships;
use App\Actions\ClassGroups\MoveClassGroupMembership;
use App\Actions\ClassGroups\SwapClassGroupMembership;
use App\Models\ClassGroup;
use App\Models\ClassGroupMembership;
use App\Models\Enrollment;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Classes\ClassRoster;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;

/**
 * A TEMPORALIDADE DAS PERTENÇAS — a metade desta funcionalidade que não se vê
 * no ecrã e que é a que não pode estar errada.
 */
class ClassGroupMembershipTest extends ClassGroupsTestCase
{
    #[Test]
    public function initial_assignment_starts_at_the_academic_year_and_asks_for_no_date(): void
    {
        [$class, $groups, $enrollments] = $this->classWithGroups();

        $this->inTenant($this->organization, function () use ($class, $groups, $enrollments): void {
            app(AssignClassGroupMemberships::class)->execute($class, [
                $enrollments[0]->id => $groups['T1']->id,
                $enrollments[1]->id => $groups['T2']->id,
            ]);
        });

        $memberships = $this->memberships();

        $this->assertCount(2, $memberships);

        foreach ($memberships as $membership) {
            $this->assertSame(self::YEAR_STARTS_ON, $membership->effective_from->toDateString());
            $this->assertNull($membership->effective_until);
        }
    }

    #[Test]
    public function a_late_enrollment_starts_on_the_day_the_student_arrived(): void
    {
        [$class, $groups] = $this->classWithGroups();
        [$late] = $this->enroll($class, 1, '2026-11-03');

        $this->inTenant($this->organization, function () use ($class, $groups, $late): void {
            app(AssignClassGroupMemberships::class)->execute($class, [$late->id => $groups['T1']->id]);
        });

        $this->assertSame('2026-11-03', $this->memberships()->sole()->effective_from->toDateString());
    }

    #[Test]
    public function moving_a_student_closes_the_old_window_the_day_before_and_opens_a_new_one(): void
    {
        [$class, $groups, $enrollments] = $this->classWithGroups();
        $this->assign($class, [$enrollments[0]->id => $groups['T1']->id]);

        $this->inTenant($this->organization, function () use ($groups, $enrollments): void {
            app(MoveClassGroupMembership::class)->execute($enrollments[0], $groups['T2'], '2026-11-15');
        });

        $windows = $this->memberships()->sortBy('effective_from')->values();

        $this->assertCount(2, $windows);
        $this->assertSame($groups['T1']->id, $windows[0]->class_group_id);
        $this->assertSame(self::YEAR_STARTS_ON, $windows[0]->effective_from->toDateString());
        $this->assertSame('2026-11-14', $windows[0]->effective_until->toDateString());
        $this->assertSame($groups['T2']->id, $windows[1]->class_group_id);
        $this->assertSame('2026-11-15', $windows[1]->effective_from->toDateString());
        $this->assertNull($windows[1]->effective_until);
    }

    #[Test]
    public function the_roster_of_a_date_before_the_move_still_answers_the_old_group(): void
    {
        [$class, $groups, $enrollments] = $this->classWithGroups();
        $this->assign($class, [
            $enrollments[0]->id => $groups['T1']->id,
            $enrollments[1]->id => $groups['T2']->id,
        ]);

        $this->inTenant($this->organization, function () use ($groups, $enrollments): void {
            app(MoveClassGroupMembership::class)->execute($enrollments[0], $groups['T2'], '2026-11-15');
        });

        $this->inTenant($this->organization, function () use ($class, $groups, $enrollments): void {
            $roster = app(ClassRoster::class);

            $before = $roster->on($class, $groups['T1'], '2026-11-14')->pluck('id')->all();
            $after = $roster->on($class, $groups['T1'], '2026-11-15')->pluck('id')->all();

            $this->assertSame([$enrollments[0]->id], $before);
            $this->assertSame([], $after);

            $this->assertSame(
                [$enrollments[1]->id],
                $roster->on($class, $groups['T2'], '2026-11-14')->pluck('id')->all(),
            );
            $this->assertEqualsCanonicalizing(
                [$enrollments[0]->id, $enrollments[1]->id],
                $roster->on($class, $groups['T2'], '2026-11-15')->pluck('id')->all(),
            );
        });
    }

    #[Test]
    public function a_move_that_would_overlap_an_existing_window_is_refused(): void
    {
        [$class, $groups, $enrollments] = $this->classWithGroups();
        $this->assign($class, [$enrollments[0]->id => $groups['T1']->id]);

        $this->inTenant($this->organization, function () use ($groups, $enrollments): void {
            app(MoveClassGroupMembership::class)->execute($enrollments[0], $groups['T2'], '2026-11-15');
        });

        // Recuar para antes da janela que já existe reescreveria história.
        $this->expectException(ValidationException::class);

        $this->inTenant($this->organization, function () use ($groups, $enrollments): void {
            app(MoveClassGroupMembership::class)->execute($enrollments[0], $groups['T1'], '2026-10-01');
        });
    }

    /**
     * O BECO DA MONTAGEM, fechado: distribuir os alunos e logo a seguir dar-se
     * conta de que um deles ficou no grupo errado.
     *
     * A data da correção é o próprio dia em que a pertença começou. Fechar a
     * janela nesse dia produziria `effective_until < effective_from` — que a
     * CHECK da tabela recusa —, e a única data que passava era o dia seguinte,
     * deixando escrito que o aluno esteve um dia em T1. Nunca esteve.
     */
    #[Test]
    public function correcting_an_assignment_on_the_day_it_started_rewrites_it_instead_of_splitting(): void
    {
        [$class, $groups, $enrollments] = $this->classWithGroups();
        $this->assign($class, [$enrollments[0]->id => $groups['T1']->id]);

        $this->inTenant($this->organization, function () use ($groups, $enrollments): void {
            app(MoveClassGroupMembership::class)->execute($enrollments[0], $groups['T2'], self::YEAR_STARTS_ON);
        });

        $membership = $this->memberships()->sole();

        $this->assertSame($groups['T2']->id, $membership->class_group_id);
        $this->assertSame(self::YEAR_STARTS_ON, $membership->effective_from->toDateString());
        $this->assertNull($membership->effective_until);
    }

    #[Test]
    public function correcting_an_assignment_to_no_group_removes_the_window_it_created(): void
    {
        [$class, $groups, $enrollments] = $this->classWithGroups();
        $this->assign($class, [$enrollments[0]->id => $groups['T1']->id]);

        $this->inTenant($this->organization, function () use ($enrollments): void {
            app(MoveClassGroupMembership::class)->execute($enrollments[0], null, self::YEAR_STARTS_ON);
        });

        $this->assertCount(0, $this->memberships());
    }

    #[Test]
    public function a_correction_is_refused_when_a_later_window_already_exists(): void
    {
        [$class, $groups, $enrollments] = $this->classWithGroups();
        $this->assign($class, [$enrollments[0]->id => $groups['T1']->id]);

        $this->inTenant($this->organization, function () use ($groups, $enrollments): void {
            app(MoveClassGroupMembership::class)->execute($enrollments[0], $groups['T2'], '2026-11-15');
        });

        // A janela inicial já não é a última: reescrevê-la no sítio deixaria a
        // de novembro pendurada num passado que mudou por baixo dela.
        $this->expectException(ValidationException::class);

        $this->inTenant($this->organization, function () use ($groups, $enrollments): void {
            app(MoveClassGroupMembership::class)->execute($enrollments[0], $groups['T2'], self::YEAR_STARTS_ON);
        });
    }

    #[Test]
    public function a_swap_on_the_first_day_rewrites_both_windows_in_place(): void
    {
        [$class, $groups, $enrollments] = $this->classWithGroups();
        $this->assign($class, [
            $enrollments[0]->id => $groups['T1']->id,
            $enrollments[1]->id => $groups['T2']->id,
        ]);

        $this->inTenant($this->organization, function () use ($enrollments): void {
            app(SwapClassGroupMembership::class)->execute($enrollments[0], $enrollments[1], self::YEAR_STARTS_ON);
        });

        $memberships = $this->memberships();

        $this->assertCount(2, $memberships, 'Uma correção não pode duplicar as janelas.');
        $this->assertSame(
            $groups['T2']->id,
            $memberships->firstWhere('enrollment_id', $enrollments[0]->id)->class_group_id,
        );
        $this->assertSame(
            $groups['T1']->id,
            $memberships->firstWhere('enrollment_id', $enrollments[1]->id)->class_group_id,
        );
    }

    #[Test]
    public function a_move_outside_the_academic_year_is_refused(): void
    {
        [$class, $groups, $enrollments] = $this->classWithGroups();
        $this->assign($class, [$enrollments[0]->id => $groups['T1']->id]);

        $this->expectException(ValidationException::class);

        $this->inTenant($this->organization, function () use ($groups, $enrollments): void {
            app(MoveClassGroupMembership::class)->execute($enrollments[0], $groups['T2'], '2027-09-01');
        });
    }

    #[Test]
    public function a_student_can_be_moved_out_of_every_group_and_the_history_survives(): void
    {
        [$class, $groups, $enrollments] = $this->classWithGroups();
        $this->assign($class, [$enrollments[0]->id => $groups['T1']->id]);

        $this->inTenant($this->organization, function () use ($enrollments): void {
            app(MoveClassGroupMembership::class)->execute($enrollments[0], null, '2026-11-15');
        });

        $membership = $this->memberships()->sole();

        $this->assertSame('2026-11-14', $membership->effective_until->toDateString());

        $this->inTenant($this->organization, function () use ($class, $groups, $enrollments): void {
            $roster = app(ClassRoster::class);

            // «Sem grupo» é um estado com nome, e é o que o ecrã da turma lê.
            $this->assertNull(
                $roster->groupIdByEnrollmentOn([$enrollments[0]->id], '2026-11-15')[$enrollments[0]->id],
            );
            $this->assertSame($groups['T1']->id, $roster
                ->groupIdByEnrollmentOn([$enrollments[0]->id], '2026-11-14')[$enrollments[0]->id]);

            $counts = $roster->memberCountsOn($class, '2026-11-15');

            $this->assertSame(0, $counts[$groups['T1']->id]);
            $this->assertSame(1, $roster->memberCountsOn($class, '2026-11-14')[$groups['T1']->id]);
        });
    }

    #[Test]
    public function an_archived_group_refuses_new_members(): void
    {
        [$class, $groups, $enrollments] = $this->classWithGroups();

        $this->inTenant($this->organization, fn () => $groups['T2']->update(['archived_at' => now()]));

        $this->expectException(ValidationException::class);

        $this->inTenant($this->organization, function () use ($class, $groups, $enrollments): void {
            app(AssignClassGroupMemberships::class)->execute($class, [
                $enrollments[0]->id => $groups['T2']->fresh()->id,
            ]);
        });
    }

    #[Test]
    public function a_group_with_open_schedule_slots_cannot_be_archived(): void
    {
        [$class, $groups] = $this->classWithGroups();

        $this->inTenant($this->organization, function () use ($class, $groups): void {
            RecurringLessonSlot::create([
                'class_id' => $class->id,
                'class_group_id' => $groups['T1']->id,
                'day_of_week' => 5,
                'starts_at' => '08:30',
                'ends_at' => '09:20',
                'starts_on' => self::YEAR_STARTS_ON,
                'ends_on' => null,
            ]);
        });

        $this->expectException(ValidationException::class);

        $this->inTenant($this->organization, fn () => app(ArchiveClassGroup::class)->execute($groups['T1']));
    }

    #[Test]
    public function a_group_whose_slots_are_all_closed_can_be_archived(): void
    {
        [$class, $groups] = $this->classWithGroups();

        $this->inTenant($this->organization, function () use ($class, $groups): void {
            RecurringLessonSlot::create([
                'class_id' => $class->id,
                'class_group_id' => $groups['T1']->id,
                'day_of_week' => 5,
                'starts_at' => '08:30',
                'ends_at' => '09:20',
                'starts_on' => self::YEAR_STARTS_ON,
                'ends_on' => '2026-10-01',
            ]);

            app(ArchiveClassGroup::class)->execute($groups['T1']);
        });

        $this->assertNotNull(
            $this->inTenant($this->organization, fn () => $groups['T1']->fresh()->archived_at),
        );
    }

    #[Test]
    public function a_swap_moves_both_students_on_a_single_date(): void
    {
        [$class, $groups, $enrollments] = $this->classWithGroups();
        $this->assign($class, [
            $enrollments[0]->id => $groups['T1']->id,
            $enrollments[1]->id => $groups['T2']->id,
        ]);

        $this->inTenant($this->organization, function () use ($enrollments): void {
            app(SwapClassGroupMembership::class)->execute($enrollments[0], $enrollments[1], '2026-11-15');
        });

        $this->inTenant($this->organization, function () use ($class, $groups, $enrollments): void {
            $roster = app(ClassRoster::class);

            $this->assertSame(
                [$enrollments[1]->id],
                $roster->on($class, $groups['T1'], '2026-11-15')->pluck('id')->all(),
            );
            $this->assertSame(
                [$enrollments[0]->id],
                $roster->on($class, $groups['T2'], '2026-11-15')->pluck('id')->all(),
            );

            // E a véspera continua a dizer o contrário.
            $this->assertSame(
                [$enrollments[0]->id],
                $roster->on($class, $groups['T1'], '2026-11-14')->pluck('id')->all(),
            );
        });

        $this->assertCount(4, $this->memberships());
    }

    #[Test]
    public function a_swap_that_fails_on_the_second_student_moves_neither(): void
    {
        [$class, $groups, $enrollments] = $this->classWithGroups();
        $this->assign($class, [
            $enrollments[0]->id => $groups['T1']->id,
            $enrollments[1]->id => $groups['T2']->id,
        ]);

        // O segundo aluno passa a ter uma janela que começa DEPOIS da data da
        // permuta: fechá-la em 14 e abrir outra em 15 sobreporia-se, e a ação
        // recusa. O primeiro aluno já tinha sido fechado nesse ponto — o teste
        // existe para provar que o rollback o desfaz.
        $this->inTenant($this->organization, function () use ($groups, $enrollments): void {
            app(MoveClassGroupMembership::class)->execute($enrollments[1], $groups['T1'], '2026-12-01');
        });

        try {
            $this->inTenant($this->organization, function () use ($enrollments): void {
                app(SwapClassGroupMembership::class)->execute($enrollments[0], $enrollments[1], '2026-11-15');
            });
            $this->fail('A permuta devia ter sido recusada.');
        } catch (ValidationException) {
            // esperado
        }

        $first = $this->memberships()
            ->where('enrollment_id', $enrollments[0]->id)
            ->sole();

        $this->assertNull($first->effective_until, 'A pertença do primeiro aluno não podia ter sido fechada.');
        $this->assertSame($groups['T1']->id, $first->class_group_id);
    }

    #[Test]
    public function a_swap_needs_two_different_students_in_two_different_groups(): void
    {
        [$class, $groups, $enrollments] = $this->classWithGroups();
        $this->assign($class, [
            $enrollments[0]->id => $groups['T1']->id,
            $enrollments[1]->id => $groups['T1']->id,
        ]);

        $this->expectException(ValidationException::class);

        $this->inTenant($this->organization, function () use ($enrollments): void {
            app(SwapClassGroupMembership::class)->execute($enrollments[0], $enrollments[1], '2026-11-15');
        });
    }

    #[Test]
    public function a_group_from_another_class_never_accepts_this_class_students(): void
    {
        [$class, , $enrollments] = $this->classWithGroups();
        $otherClass = $this->schoolClassFor($this->teacher);

        $foreignGroup = $this->inTenant($this->organization, fn (): ClassGroup => ClassGroup::create([
            'class_id' => $otherClass->id,
            'label' => 'T1',
            'position' => 0,
        ]));

        $this->expectException(ValidationException::class);

        $this->inTenant($this->organization, function () use ($class, $foreignGroup, $enrollments): void {
            app(AssignClassGroupMemberships::class)->execute($class, [
                $enrollments[0]->id => $foreignGroup->id,
            ]);
        });
    }

    #[Test]
    public function a_group_from_another_organization_is_invisible(): void
    {
        $otherTeacher = User::factory()->create();
        $otherOrganization = $otherTeacher->personalOrganization();
        $otherClass = $this->schoolClassFor($otherTeacher, $otherOrganization);

        $this->inTenant($otherOrganization, fn () => ClassGroup::create([
            'class_id' => $otherClass->id,
            'label' => 'T1',
            'position' => 0,
        ]));

        $this->inTenant($this->organization, function (): void {
            $this->assertSame(0, ClassGroup::query()->count());
        });
    }

    /**
     * @return array{0: SchoolClass, 1: array<string, ClassGroup>, 2: list<Enrollment>}
     */
    protected function classWithGroups(): array
    {
        $class = $this->schoolClassFor($this->teacher);
        $enrollments = $this->enroll($class, 4);

        $groups = $this->inTenant($this->organization, function () use ($class): array {
            return [
                'T1' => ClassGroup::create(['class_id' => $class->id, 'label' => 'T1', 'position' => 0]),
                'T2' => ClassGroup::create(['class_id' => $class->id, 'label' => 'T2', 'position' => 1]),
            ];
        });

        return [$class, $groups, $enrollments];
    }

    /**
     * @param  array<int, int>  $assignments
     */
    protected function assign(SchoolClass $class, array $assignments): void
    {
        $this->inTenant($this->organization, function () use ($class, $assignments): void {
            app(AssignClassGroupMemberships::class)->execute($class, $assignments);
        });
    }

    /**
     * @return Collection<int, ClassGroupMembership>
     */
    protected function memberships(): Collection
    {
        return $this->inTenant(
            $this->organization,
            fn () => ClassGroupMembership::query()->get(),
        );
    }
}
