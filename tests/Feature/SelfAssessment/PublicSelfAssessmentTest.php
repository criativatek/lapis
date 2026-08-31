<?php

namespace Tests\Feature\SelfAssessment;

use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentFilledBy;
use App\Models\SelfAssessmentQuestionRole;
use App\Models\SelfAssessmentStatus;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Assessment\SelfAssessmentTemplateProvider;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The student's no-login, signed-link entry point into a self-assessment (§15) —
 * gated behind its own Pro-tier module (self_assessment_links), separate from
 * self_assessments itself (which every plan, including Base, already has).
 */
class PublicSelfAssessmentTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{string, string, string} class ulid, period ulid, enrollment ulid */
    private function seedContext(): array
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        return app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            $enrollment = $class->enrollments()->orderBy('class_number')->first();

            return [$class->ulid, $period->ulid, $enrollment->ulid];
        });
    }

    private function subscribeToPro(User $user): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $user->personalOrganization()->getKey())
            ->delete();

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $user->personalOrganization()->getKey(),
            'plan_id' => Plan::where('key', 'pro')->firstOrFail()->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now()->subDay(),
        ]);

        app(Entitlements::class)->flush();
    }

    private function signedEditUrl(string $classUlid, string $periodUlid, string $enrollmentUlid, ?Carbon $expiration = null): string
    {
        return URL::temporarySignedRoute('self-assessments.public.edit', $expiration ?? Carbon::now()->addDays(7), [
            'classUlid' => $classUlid,
            'periodUlid' => $periodUlid,
            'enrollmentUlid' => $enrollmentUlid,
        ]);
    }

    #[Test]
    public function the_links_page_is_blocked_on_the_base_plan(): void
    {
        [$classUlid, $periodUlid] = $this->seedContext();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $this->actingAs($teacher)->get("/classes/{$classUlid}/self-assessments/{$periodUlid}/links")->assertForbidden();
    }

    #[Test]
    public function a_pro_teacher_gets_one_signed_link_per_student(): void
    {
        [$classUlid, $periodUlid] = $this->seedContext();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();
        $this->subscribeToPro($teacher);

        $this->actingAs($teacher)->get("/classes/{$classUlid}/self-assessments/{$periodUlid}/links")->assertInertia(
            fn ($page) => $page
                ->component('self-assessments/Links')
                ->where('rows', fn ($rows) => count($rows) > 0 && str_contains($rows[0]['link'], 'signature='))
        );
    }

    #[Test]
    public function the_signed_link_shows_the_students_own_form_without_login(): void
    {
        [$classUlid, $periodUlid, $enrollmentUlid] = $this->seedContext();
        $this->subscribeToPro(User::where('email', 'ana.martins@lapis.test')->firstOrFail());

        $url = $this->signedEditUrl($classUlid, $periodUlid, $enrollmentUlid);

        // No actingAs() at all — this is the whole point.
        $this->get($url)->assertOk()->assertInertia(
            fn ($page) => $page->component('self-assessments/PublicEdit')->has('questions')
        );
    }

    #[Test]
    public function two_different_signed_links_identify_two_different_students(): void
    {
        [$classUlid, $periodUlid, $firstEnrollmentUlid] = $this->seedContext();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();
        $this->subscribeToPro($teacher);

        [$secondEnrollmentUlid, $firstName, $secondName] = app(CurrentOrganization::class)->runFor(
            $teacher->personalOrganization(),
            function () use ($classUlid, $firstEnrollmentUlid) {
                $class = SchoolClass::where('ulid', $classUlid)->firstOrFail();
                $first = $class->enrollments()->where('ulid', $firstEnrollmentUlid)->with('student.identity')->firstOrFail();
                $second = $class->enrollments()->where('ulid', '!=', $firstEnrollmentUlid)
                    ->with('student.identity')->orderBy('class_number')->first();

                return [$second?->ulid, $first->student->identity->display_name, $second?->student->identity->display_name];
            },
        );

        $this->assertNotNull($secondEnrollmentUlid, 'Seed class needs at least two students for this test.');
        $this->assertNotSame($firstName, $secondName);

        // The identity shown comes from the enrollment the SIGNATURE resolves
        // to, never from anything the caller can choose — this is the whole
        // point of proving A's link shows A and B's link shows B.
        $this->get($this->signedEditUrl($classUlid, $periodUlid, $firstEnrollmentUlid))
            ->assertInertia(fn ($page) => $page->where('student', $firstName));
        $this->get($this->signedEditUrl($classUlid, $periodUlid, $secondEnrollmentUlid))
            ->assertInertia(fn ($page) => $page->where('student', $secondName));
    }

    #[Test]
    public function the_signed_link_never_carries_the_students_name(): void
    {
        [$classUlid, $periodUlid, $enrollmentUlid] = $this->seedContext();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $name = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($classUlid, $enrollmentUlid) {
            $class = SchoolClass::where('ulid', $classUlid)->firstOrFail();

            return $class->enrollments()->where('ulid', $enrollmentUlid)->with('student.identity')->firstOrFail()
                ->student->identity->display_name;
        });

        $url = $this->signedEditUrl($classUlid, $periodUlid, $enrollmentUlid);

        // The route is built entirely from ULIDs plus Laravel's own signature
        // machinery — the student's name has no path here to leak into what
        // gets shared, projected or pasted into a chat.
        $this->assertStringNotContainsString($name, $url);
    }

    #[Test]
    public function an_appended_query_parameter_invalidates_the_signature_instead_of_being_read(): void
    {
        [$classUlid, $periodUlid, $enrollmentUlid] = $this->seedContext();

        $url = $this->signedEditUrl($classUlid, $periodUlid, $enrollmentUlid);
        $separator = str_contains($url, '?') ? '&' : '?';

        // A caller cannot smuggle a display name (or anything else) in
        // through the query string: Laravel's signature covers the exact
        // query it was issued with, so any addition invalidates it outright
        // rather than being silently accepted or ignored.
        $this->get($url.$separator.'student_name=Nome+Errado')->assertForbidden();
    }

    #[Test]
    public function an_unsigned_link_is_rejected(): void
    {
        [$classUlid, $periodUlid, $enrollmentUlid] = $this->seedContext();

        $this->get("/auto/{$classUlid}/{$periodUlid}/{$enrollmentUlid}")->assertForbidden();
    }

    #[Test]
    public function a_tampered_link_is_rejected(): void
    {
        [$classUlid, $periodUlid, $enrollmentUlid] = $this->seedContext();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $otherEnrollmentUlid = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($classUlid, $enrollmentUlid) {
            $class = SchoolClass::where('ulid', $classUlid)->firstOrFail();

            return $class->enrollments()->where('ulid', '!=', $enrollmentUlid)->orderBy('class_number')->first()?->ulid;
        });
        $this->assertNotNull($otherEnrollmentUlid, 'Seed class needs at least two students for this test.');

        $url = $this->signedEditUrl($classUlid, $periodUlid, $enrollmentUlid);
        $swapped = str_replace($enrollmentUlid, $otherEnrollmentUlid, $url);

        $this->get($swapped)->assertForbidden();
    }

    #[Test]
    public function an_expired_link_is_rejected(): void
    {
        [$classUlid, $periodUlid, $enrollmentUlid] = $this->seedContext();

        $url = $this->signedEditUrl($classUlid, $periodUlid, $enrollmentUlid, Carbon::now()->subMinute());

        $this->get($url)->assertForbidden();
    }

    #[Test]
    public function submitting_the_link_records_the_student_as_the_author(): void
    {
        [$classUlid, $periodUlid, $enrollmentUlid] = $this->seedContext();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();
        $this->subscribeToPro($teacher);

        $url = $this->signedEditUrl($classUlid, $periodUlid, $enrollmentUlid);

        $rationaleId = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($classUlid) {
            $class = SchoolClass::where('ulid', $classUlid)->firstOrFail();

            return app(SelfAssessmentTemplateProvider::class)->forClass($class)
                ->questions->firstWhere('role', SelfAssessmentQuestionRole::Rationale)->id;
        });

        $this->post($url, ['answers' => [], 'texts' => [$rationaleId => 'Acho que fui bem na leitura.']])->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($rationaleId): void {
            $selfAssessment = SelfAssessment::with('responses')->firstOrFail();
            $this->assertSame(SelfAssessmentFilledBy::Student, $selfAssessment->filled_by);
            $this->assertSame(SelfAssessmentStatus::Submitted, $selfAssessment->status);
            $this->assertSame(
                'Acho que fui bem na leitura.',
                $selfAssessment->responses->firstWhere('self_assessment_question_id', $rationaleId)->text_value,
            );
        });
    }

    #[Test]
    public function a_link_stops_working_the_moment_the_plan_no_longer_includes_it(): void
    {
        [$classUlid, $periodUlid, $enrollmentUlid] = $this->seedContext();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        // Generated while on Pro...
        $this->subscribeToPro($teacher);
        $url = $this->signedEditUrl($classUlid, $periodUlid, $enrollmentUlid);

        // ...but the organization is back on Base by the time it's opened.
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $teacher->personalOrganization()->getKey())
            ->delete();
        app(Entitlements::class)->flush();

        $this->get($url)->assertForbidden();
    }

    #[Test]
    public function an_enrollment_from_a_different_class_is_rejected(): void
    {
        [$classUlid, $periodUlid] = $this->seedContext();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $otherClassEnrollmentUlid = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($classUlid) {
            $otherClass = SchoolClass::where('ulid', '!=', $classUlid)->first();

            return $otherClass?->enrollments()->first()?->ulid;
        });

        if ($otherClassEnrollmentUlid === null) {
            $this->markTestSkipped('Demo data needs a second class with at least one enrollment for this test.');
        }

        // Signed for THIS exact (class, period, mismatched enrollment) triple —
        // a valid signature, just an internally inconsistent one.
        $url = $this->signedEditUrl($classUlid, $periodUlid, $otherClassEnrollmentUlid);

        $this->get($url)->assertNotFound();
    }
}
