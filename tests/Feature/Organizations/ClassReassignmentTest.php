<?php

namespace Tests\Feature\Organizations;

use App\Models\AuditEvent;
use App\Models\Enrollment;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClassReassignmentTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Organization, User} */
    private function institutionalOrganization(): array
    {
        $owner = User::factory()->withoutOrganization()->create();
        $organization = Organization::factory()->institutional()->create(['owner_id' => $owner->id]);
        $organization->members()->attach($owner, ['joined_at' => now()]);

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->id,
            'plan_id' => Plan::where('key', 'institutional')->firstOrFail()->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now(),
        ]);

        return [$organization, $owner];
    }

    private function member(Organization $organization): User
    {
        $member = User::factory()->create();
        $organization->members()->attach($member, ['joined_at' => now()]);

        return $member;
    }

    private function schoolClass(Organization $organization, User $teacher): SchoolClass
    {
        return app(CurrentOrganization::class)->runFor($organization, function () use ($organization, $teacher): SchoolClass {
            $class = SchoolClass::factory()->recycle($organization)->create();
            $class->teachers()->attach($teacher, ['role' => 'owner']);

            return $class;
        });
    }

    #[Test]
    public function departure_with_no_classes_succeeds_and_a_sole_teachers_class_needs_reassignment(): void
    {
        [$organization] = $this->institutionalOrganization();
        $teacherWithoutClasses = $this->member($organization);

        $this->actingAs($teacherWithoutClasses)->withSession(['organization_id' => $organization->id])
            ->post('/organizations/leave')->assertRedirect(route('dashboard'));

        $teacher = $this->member($organization);
        $class = $this->schoolClass($organization, $teacher);

        $this->actingAs($teacher)->withSession(['organization_id' => $organization->id])
            ->post('/organizations/leave')->assertRedirect(route('dashboard'));

        $this->assertTrue(app(CurrentOrganization::class)->runFor(
            $organization,
            fn () => SchoolClass::query()->needingReassignment()->whereKey($class->id)->exists(),
        ));
    }

    #[Test]
    public function a_remaining_co_teacher_keeps_the_class_out_of_reassignment(): void
    {
        [$organization] = $this->institutionalOrganization();
        $departingTeacher = $this->member($organization);
        $remainingTeacher = $this->member($organization);
        $class = $this->schoolClass($organization, $departingTeacher);
        $class->teachers()->attach($remainingTeacher, ['role' => 'co_teacher']);

        $this->actingAs($departingTeacher)->withSession(['organization_id' => $organization->id])
            ->post('/organizations/leave');

        app(CurrentOrganization::class)->runFor($organization, function () use ($class, $remainingTeacher): void {
            $this->assertFalse(SchoolClass::query()->needingReassignment()->whereKey($class->id)->exists());
            $this->assertTrue($class->teachers()->whereKey($remainingTeacher->id)->exists());
        });
    }

    #[Test]
    public function removal_handles_no_classes_and_derives_reassignment_from_remaining_teachers(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();
        $memberWithoutClasses = $this->member($organization);

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->delete('/team/members', ['member' => $memberWithoutClasses->id])
            ->assertRedirect();

        $soleTeacher = $this->member($organization);
        $orphanedClass = $this->schoolClass($organization, $soleTeacher);

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->delete('/team/members', ['member' => $soleTeacher->id])
            ->assertRedirect();

        $departingTeacher = $this->member($organization);
        $remainingTeacher = $this->member($organization);
        $sharedClass = $this->schoolClass($organization, $departingTeacher);
        $sharedClass->teachers()->attach($remainingTeacher, ['role' => 'co_teacher']);

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->delete('/team/members', ['member' => $departingTeacher->id])
            ->assertRedirect();

        app(CurrentOrganization::class)->runFor($organization, function () use ($orphanedClass, $sharedClass, $remainingTeacher): void {
            $this->assertTrue(SchoolClass::query()->needingReassignment()->whereKey($orphanedClass->id)->exists());
            $this->assertFalse(SchoolClass::query()->needingReassignment()->whereKey($sharedClass->id)->exists());
            $this->assertTrue($sharedClass->teachers()->whereKey($remainingTeacher->id)->exists());
        });
    }

    #[Test]
    public function leaving_preserves_the_class_student_enrollment_evidence_and_authorship(): void
    {
        [$organization] = $this->institutionalOrganization();
        $departingTeacher = $this->member($organization);
        $class = $this->schoolClass($organization, $departingTeacher);

        [$enrollment, $evidence, $countsBefore] = app(CurrentOrganization::class)->runFor(
            $organization,
            function () use ($organization, $class, $departingTeacher): array {
                $enrollment = Enrollment::factory()->recycle($organization)->create(['class_id' => $class->id]);
                $evidence = EvidenceRecord::create([
                    'class_id' => $class->id,
                    'enrollment_id' => $enrollment->id,
                    'occurred_at' => now(),
                    'kind' => EvidenceKind::Note,
                    'description' => 'Registo que deve sobreviver à saída.',
                    'created_by' => $departingTeacher->id,
                ]);

                return [
                    $enrollment,
                    $evidence,
                    [
                        'classes' => SchoolClass::count(),
                        'students' => $enrollment->student()->count(),
                        'enrollments' => Enrollment::count(),
                        'evidence' => EvidenceRecord::count(),
                    ],
                ];
            },
        );

        $this->actingAs($departingTeacher)->withSession(['organization_id' => $organization->id])
            ->post('/organizations/leave')
            ->assertRedirect(route('dashboard'));

        app(CurrentOrganization::class)->runFor($organization, function () use ($class, $enrollment, $evidence, $departingTeacher, $countsBefore): void {
            $this->assertSame($countsBefore['classes'], SchoolClass::count());
            $this->assertSame($countsBefore['students'], $enrollment->student()->count());
            $this->assertSame($countsBefore['enrollments'], Enrollment::count());
            $this->assertSame($countsBefore['evidence'], EvidenceRecord::count());
            $this->assertDatabaseHas('classes', ['id' => $class->id]);
            $this->assertDatabaseHas('enrollments', ['id' => $enrollment->id, 'student_id' => $enrollment->student_id]);
            $this->assertSame($departingTeacher->id, $evidence->fresh()->created_by);
        });
    }

    #[Test]
    public function reassignment_preserves_students_enrollments_evidence_and_historical_authorship(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();
        $departingTeacher = $this->member($organization);
        $newTeacher = $this->member($organization);
        $class = $this->schoolClass($organization, $departingTeacher);

        [$enrollment, $evidence] = app(CurrentOrganization::class)->runFor($organization, function () use ($organization, $class, $departingTeacher): array {
            $enrollment = Enrollment::factory()->recycle($organization)->create(['class_id' => $class->id]);
            $evidence = EvidenceRecord::create([
                'class_id' => $class->id,
                'enrollment_id' => $enrollment->id,
                'occurred_at' => now(),
                'kind' => EvidenceKind::Note,
                'description' => 'Observação histórica.',
                'created_by' => $departingTeacher->id,
            ]);

            return [$enrollment, $evidence];
        });

        $studentId = $enrollment->student_id;
        $createdBy = $evidence->created_by;

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->delete('/team/members', ['member' => $departingTeacher->id]);
        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->post("/classes/reassignment/{$class->ulid}/assign", ['member' => $newTeacher->id])
            ->assertRedirect();

        app(CurrentOrganization::class)->runFor($organization, function () use ($class, $newTeacher, $enrollment, $evidence, $studentId, $createdBy): void {
            $this->assertTrue($class->teachers()->whereKey($newTeacher->id)->wherePivot('role', 'owner')->exists());
            $this->assertFalse(SchoolClass::query()->needingReassignment()->whereKey($class->id)->exists());
            $this->assertSame($studentId, $enrollment->fresh()->student_id);
            $this->assertSame($createdBy, $evidence->fresh()->created_by);
            $this->assertDatabaseHas('students', ['id' => $studentId]);
            $this->assertDatabaseHas('enrollments', ['id' => $enrollment->id, 'class_id' => $class->id]);
        });

        $this->assertTrue(app(CurrentOrganization::class)->runFor(
            $organization,
            fn () => AuditEvent::where('event', 'class.reassigned')
                ->where('causer_id', $owner->id)
                ->where('subject_id', $class->id)
                ->exists(),
        ));
    }

    #[Test]
    public function reassignment_rejects_cross_tenant_targets_and_impersonation(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();
        [$otherOrganization] = $this->institutionalOrganization();
        $departingTeacher = $this->member($organization);
        $otherMember = $this->member($otherOrganization);
        $class = $this->schoolClass($organization, $departingTeacher);

        $this->actingAs($departingTeacher)->withSession(['organization_id' => $organization->id])
            ->post('/organizations/leave');

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->post("/classes/reassignment/{$class->ulid}/assign", ['member' => $otherMember->id])
            ->assertNotFound();
        $this->actingAs($owner)->withSession(['organization_id' => $organization->id, 'impersonator_id' => 999])
            ->post("/classes/reassignment/{$class->ulid}/assign", ['member' => $owner->id])
            ->assertForbidden();

        $this->assertTrue(app(CurrentOrganization::class)->runFor(
            $organization,
            fn () => SchoolClass::query()->needingReassignment()->whereKey($class->id)->exists(),
        ));
    }
}
