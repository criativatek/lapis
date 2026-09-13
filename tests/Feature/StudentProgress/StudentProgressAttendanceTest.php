<?php

namespace Tests\Feature\StudentProgress;

use App\Models\AttendanceStatus;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\LessonStatus;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Ai\Providers\FakeAiTextProvider;
use App\Services\Assessment\Progress\BuildStudentProgress;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EntitlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O cartão «Assiduidade» de Evolução do Aluno (§ do briefing de assiduidade):
 * presente na organização com o módulo `lessons` (Pro), ausente na Base, e
 * NUNCA no payload que alimenta a IA (§77 do briefing).
 */
class StudentProgressAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
        $this->organization = $this->teacher->personalOrganization();
        $this->seed(EntitlementsSeeder::class);
    }

    private function givePlan(string $key): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $this->organization->id],
            [
                'plan_id' => Plan::where('key', $key)->firstOrFail()->id,
                'status' => SubscriptionStatus::Active,
                'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
                'ends_at' => null,
            ],
        );

        app(Entitlements::class)->flush();
    }

    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }

    private function schoolClass(): SchoolClass
    {
        return $this->asTenant(fn (): SchoolClass => SchoolClass::where('label', '7.º A')->firstOrFail());
    }

    private function enrollment(int $classNumber): Enrollment
    {
        return $this->asTenant(fn (): Enrollment => $this->schoolClass()->enrollments()->where('class_number', $classNumber)->firstOrFail());
    }

    /** Uma aula consolidada com uma falta, mais uma lecionada sem registo. */
    private function seedAttendance(Enrollment $enrollment): void
    {
        $this->asTenant(function () use ($enrollment): void {
            $class = $this->schoolClass();

            $recorded = Lesson::create([
                'class_id' => $class->id,
                'starts_at' => '2026-10-08 09:30:00',
                'ends_at' => '2026-10-08 10:20:00',
                'lesson_number' => 1,
                'status' => LessonStatus::Taught,
                'attendance_recorded_at' => now(),
                'attendance_recorded_by' => $this->teacher->id,
                'created_by' => $this->teacher->id,
            ]);

            LessonAttendance::create([
                'lesson_id' => $recorded->id,
                'enrollment_id' => $enrollment->id,
                'student_id' => $enrollment->student_id,
                'status' => AttendanceStatus::Absent,
                'updated_by' => $this->teacher->id,
            ]);

            Lesson::create([
                'class_id' => $class->id,
                'starts_at' => '2026-10-15 09:30:00',
                'ends_at' => '2026-10-15 10:20:00',
                'lesson_number' => 2,
                'status' => LessonStatus::Taught,
                'attendance_recorded_at' => null,
                'created_by' => $this->teacher->id,
            ]);
        });
    }

    #[Test]
    public function the_card_is_absent_on_base_which_has_no_lessons_module(): void
    {
        $this->givePlan('base');
        $enrollment = $this->enrollment(1);
        $this->seedAttendance($enrollment);
        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->missing('attendance'));
    }

    #[Test]
    public function the_card_shows_totals_and_rows_on_pro_which_has_lessons(): void
    {
        $this->givePlan('pro');
        $enrollment = $this->enrollment(1);
        $this->seedAttendance($enrollment);
        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('attendance.totals.recorded', 1)
                ->where('attendance.totals.present', 0)
                ->where('attendance.totals.absent', 1)
                ->where('attendance.totals.not_recorded', 1)
                ->has('attendance.rows', 2));
    }

    #[Test]
    public function attendance_never_reaches_the_ai_strategy_suggestion_prompt(): void
    {
        $this->givePlan('pro');
        config(['lapis.ai.driver' => 'fake']);
        $engine = new FakeAiTextProvider('modelo-de-teste');
        $engine->willReturn(
            "NOME: Leitura orientada\nOBJETIVO: melhorar a leitura.\nAPLICACAO: leitura orientada.\nFREQUENCIA: 2x por semana\nDURACAO: 4 semanas\nINDICADOR: número de textos lidos\nREVISAO: daqui a 4 semanas",
        );
        $this->app->instance(FakeAiTextProvider::class, $engine);

        $enrollment = $this->enrollment(1);
        // Uma inscrição noutra turma, com uma marca textual distintiva, para
        // provar que nem sequer o RÓTULO da turma da aula («7.º A») viaja —
        // a garantia estrutural é que `$progress` (o único array passado ao
        // sugestor) nunca contém a chave `attendance`, mas o teste verifica-o
        // de fora para dentro, no que realmente é enviado ao motor.
        $this->seedAttendance($enrollment);
        $class = $this->schoolClass();

        $domain = $this->asTenant(fn () => app(BuildStudentProgress::class)
            ->for($class, $enrollment)['domains']['rows'][0]);

        $this->actingAs($this->teacher)
            ->from("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            ->post("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}/sugestao-estrategia", [
                'domain_id' => $domain['domain_id'],
                'purpose' => 'improvement',
            ])
            ->assertRedirect();

        $this->assertNotEmpty($engine->received);

        foreach ($engine->received as $request) {
            $this->assertStringNotContainsString('attendance', mb_strtolower($request->content));
            $this->assertStringNotContainsString('assiduidade', mb_strtolower($request->content));
            $this->assertStringNotContainsString('falta', mb_strtolower($request->content));
            $this->assertStringNotContainsString('presença', mb_strtolower($request->content));
        }
    }
}
