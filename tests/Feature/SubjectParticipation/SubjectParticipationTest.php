<?php

namespace Tests\Feature\SubjectParticipation;

use App\Actions\SubjectParticipation\DeleteExternalSubjectResult;
use App\Actions\SubjectParticipation\MarkNotAttendingSubject;
use App\Actions\SubjectParticipation\ReactivateSubjectParticipation;
use App\Actions\SubjectParticipation\RecordExternalSubjectResult;
use App\Models\AuditEvent;
use App\Models\EnrollmentStatus;
use App\Models\ExternalSubjectResult;
use App\Models\SubjectParticipation;
use App\Models\SubjectParticipationReason;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;

class SubjectParticipationTest extends SubjectParticipationTestCase
{
    #[Test]
    public function marking_not_attending_leaves_the_enrollment_active_and_on_the_roster(): void
    {
        $class = $this->schoolClassFor();
        $enrollment = $this->enrollStudent($class);

        $this->inTenant($this->organization, function () use ($class, $enrollment): void {
            app(MarkNotAttendingSubject::class)->execute(
                $enrollment,
                SubjectParticipationReason::AlternativeSubject,
                '2026-11-15',
                reasonDetail: 'PLNM',
            );

            $enrollment->refresh();

            $this->assertSame(EnrollmentStatus::Active, $enrollment->status);
            $this->assertNull($enrollment->status_reason);
            $this->assertNull($enrollment->left_on);
            $this->assertTrue(
                $class->enrollments()->whereKey($enrollment->getKey())->exists(),
            );
        });
    }

    #[Test]
    public function other_subjects_of_the_same_class_label_are_untouched(): void
    {
        $portuguese = $this->schoolClassFor(label: '8.º F');
        $plnm = $this->sameLabelDifferentSubject($portuguese);

        $portugueseEnrollment = $this->enrollStudent($portuguese);
        $plnmEnrollment = $this->sameStudentEnrolledIn($plnm, $portugueseEnrollment);

        $this->inTenant($this->organization, function () use ($portugueseEnrollment, $plnmEnrollment): void {
            app(MarkNotAttendingSubject::class)->execute(
                $portugueseEnrollment,
                SubjectParticipationReason::AlternativeSubject,
                '2026-11-15',
                reasonDetail: 'PLNM',
            );

            $this->assertSame(1, SubjectParticipation::query()->where('enrollment_id', $portugueseEnrollment->getKey())->count());
            $this->assertSame(0, SubjectParticipation::query()->where('enrollment_id', $plnmEnrollment->getKey())->count());

            $plnmEnrollment->refresh();
            $this->assertSame(EnrollmentStatus::Active, $plnmEnrollment->status);
        });
    }

    #[Test]
    public function reactivation_closes_the_window_and_preserves_it(): void
    {
        $class = $this->schoolClassFor();
        $enrollment = $this->enrollStudent($class);

        $this->inTenant($this->organization, function () use ($enrollment): void {
            $participation = app(MarkNotAttendingSubject::class)->execute(
                $enrollment,
                SubjectParticipationReason::AlternativeSubject,
                '2026-11-15',
                reasonDetail: 'PLNM',
            );

            app(ReactivateSubjectParticipation::class)->execute($enrollment, '2027-01-02');

            $participation->refresh();

            $this->assertNotNull($participation->effective_until);
            $this->assertSame('2027-01-01', $participation->effective_until->toDateString());
            $this->assertSame('2026-11-15', $participation->effective_from->toDateString());
            // Continua a existir: história, não apagada.
            $this->assertSame(1, SubjectParticipation::query()->where('enrollment_id', $enrollment->getKey())->count());
        });
    }

    #[Test]
    public function reactivating_on_the_same_day_it_started_deletes_the_window(): void
    {
        $class = $this->schoolClassFor();
        $enrollment = $this->enrollStudent($class);

        $this->inTenant($this->organization, function () use ($enrollment): void {
            app(MarkNotAttendingSubject::class)->execute(
                $enrollment,
                SubjectParticipationReason::AlternativeSubject,
                '2026-11-15',
                reasonDetail: 'PLNM',
            );

            app(ReactivateSubjectParticipation::class)->execute($enrollment, '2026-11-15');

            $this->assertSame(0, SubjectParticipation::query()->where('enrollment_id', $enrollment->getKey())->count());
        });
    }

    #[Test]
    public function participation_in_vigor_is_temporal(): void
    {
        $class = $this->schoolClassFor();
        $enrollment = $this->enrollStudent($class);

        $this->inTenant($this->organization, function () use ($enrollment): void {
            app(MarkNotAttendingSubject::class)->execute(
                $enrollment,
                SubjectParticipationReason::AlternativeSubject,
                '2026-11-15',
                reasonDetail: 'PLNM',
            );

            $this->assertFalse(
                SubjectParticipation::query()->where('enrollment_id', $enrollment->getKey())->inVigorOn('2026-11-14')->exists(),
            );
            $this->assertTrue(
                SubjectParticipation::query()->where('enrollment_id', $enrollment->getKey())->inVigorOn('2026-11-15')->exists(),
            );
            $this->assertTrue(
                SubjectParticipation::query()->where('enrollment_id', $enrollment->getKey())->inVigorOn('2026-12-01')->exists(),
            );
        });
    }

    #[Test]
    public function reason_alternative_subject_carries_the_free_text_detail(): void
    {
        $class = $this->schoolClassFor();
        $enrollment = $this->enrollStudent($class);

        $this->inTenant($this->organization, function () use ($enrollment): void {
            $participation = app(MarkNotAttendingSubject::class)->execute(
                $enrollment,
                SubjectParticipationReason::AlternativeSubject,
                '2026-11-15',
                reasonDetail: 'PLNM',
            );

            $this->assertSame(SubjectParticipationReason::AlternativeSubject, $participation->reason);
            $this->assertSame('PLNM', $participation->reason_detail);
        });
    }

    #[Test]
    public function external_result_refuses_when_nothing_is_given(): void
    {
        $class = $this->schoolClassFor();
        $enrollment = $this->enrollStudent($class);

        $this->inTenant($this->organization, function () use ($enrollment): void {
            $this->expectException(ValidationException::class);

            app(RecordExternalSubjectResult::class)->execute(
                $enrollment,
                periodId: null,
                origin: 'escola_de_origem',
                recordedOn: '2026-11-15',
            );
        });
    }

    #[Test]
    public function a_second_year_level_row_is_refused_because_the_unique_cannot_see_it(): void
    {
        $class = $this->schoolClassFor();
        $enrollment = $this->enrollStudent($class);

        $this->inTenant($this->organization, function () use ($enrollment): void {
            app(RecordExternalSubjectResult::class)->execute(
                $enrollment,
                periodId: null,
                origin: 'escola_de_origem',
                recordedOn: '2026-11-15',
                levelCode: 'B',
            );

            // Não é um segundo registo: é a MESMA linha corrigida.
            app(RecordExternalSubjectResult::class)->execute(
                $enrollment,
                periodId: null,
                origin: 'escola_de_origem',
                recordedOn: '2026-12-01',
                levelCode: 'C',
            );

            $this->assertSame(1, ExternalSubjectResult::query()->where('enrollment_id', $enrollment->getKey())->count());
            $this->assertSame('C', ExternalSubjectResult::query()->where('enrollment_id', $enrollment->getKey())->first()->level_code);
        });
    }

    #[Test]
    public function audit_events_are_written_without_the_free_text_note(): void
    {
        $class = $this->schoolClassFor();
        $enrollment = $this->enrollStudent($class);

        $this->inTenant($this->organization, function () use ($enrollment): void {
            app(MarkNotAttendingSubject::class)->execute(
                $enrollment,
                SubjectParticipationReason::AlternativeSubject,
                '2026-11-15',
                reasonDetail: 'PLNM',
                note: 'Nota pedagógica sensível sobre o aluno.',
            );

            $event = AuditEvent::withoutGlobalScope('organization')
                ->where('event', 'subject_participation.marked_not_attending')
                ->latest('id')
                ->first();

            $this->assertNotNull($event);
            $properties = $event->properties ?? [];
            $this->assertArrayNotHasKey('note', $properties);
            $this->assertStringNotContainsString('sensível', json_encode($properties));
        });
    }

    #[Test]
    public function external_result_audit_events_are_written_without_the_free_text_note(): void
    {
        $class = $this->schoolClassFor();
        $enrollment = $this->enrollStudent($class);

        $this->inTenant($this->organization, function () use ($enrollment): void {
            $result = app(RecordExternalSubjectResult::class)->execute(
                $enrollment,
                periodId: null,
                origin: 'escola_de_origem',
                recordedOn: '2026-11-15',
                levelCode: 'B',
                note: 'Nota confidencial.',
            );

            app(DeleteExternalSubjectResult::class)->execute($result);

            foreach (['external_result.recorded', 'external_result.deleted'] as $eventName) {
                $event = AuditEvent::withoutGlobalScope('organization')
                    ->where('event', $eventName)
                    ->latest('id')
                    ->first();

                $this->assertNotNull($event, "evento {$eventName} não foi escrito");
                $properties = $event->properties ?? [];
                $this->assertArrayNotHasKey('note', $properties);
            }
        });
    }

    #[Test]
    public function the_window_check_rejects_effective_until_before_effective_from(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('A CHECK de intervalo só é imposta pelo MySQL/MariaDB — o SQLite dos testes não a declara.');
        }

        $class = $this->schoolClassFor();
        $enrollment = $this->enrollStudent($class);

        $this->inTenant($this->organization, function () use ($enrollment): void {
            $this->expectException(\Throwable::class);

            DB::table('subject_participations')->insert([
                'ulid' => (string) str()->ulid(),
                'organization_id' => $this->organization->id,
                'enrollment_id' => $enrollment->getKey(),
                'state' => 'not_attending',
                'reason' => 'other',
                'effective_from' => '2026-11-15',
                'effective_until' => '2026-11-10',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}
