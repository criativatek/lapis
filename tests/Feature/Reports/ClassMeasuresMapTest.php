<?php

namespace Tests\Feature\Reports;

use App\Actions\Interventions\CreateIntervention;
use App\Models\AuditEvent;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\Intervention;
use App\Models\InterventionDescriptionSource;
use App\Models\InterventionDomainRelation;
use App\Models\InterventionReview;
use App\Models\InterventionStatus;
use App\Models\InterventionTargetType;
use App\Models\InterventionType;
use App\Models\LegalMappingSource;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleOverride;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\SubscriptionStatus;
use App\Models\SupportMeasureCode;
use App\Models\SupportMeasureLevel;
use App\Models\User;
use App\Services\Reporting\ClassDocuments\ClassMeasuresMap;
use App\Services\StudentEnrollmentService;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EntitlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\Fluent\AssertableJson;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Mapa de Medidas Educativas: a class document made on the spot from
 * Estratégias e Medidas — which students have which measures, split into
 * «Implementadas» and «A implementar».
 *
 * WHAT THESE TESTS GUARD, in order of how much it would hurt to lose it:
 *
 *  - that nothing the teacher kept private reaches the page or the PDF
 *    (descriptions, reasons, follow-up notes, identifiers, dates of birth);
 *  - that `available_for_reports = false` is respected and a student who left
 *    does not appear;
 *  - that a class-wide measure is decided by `target_type`, not by a NULL;
 *  - that producing the document changes nothing in the history it reads;
 *  - that the screen and the PDF are the same document.
 *
 * The roster is the demo class «7.º A»: six students numbered 1 to 6.
 */
class ClassMeasuresMapTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'reports/class-documents/MeasuresMap';

    protected User $teacher;

    protected Organization $organization;

    protected SchoolClass $class;

    private int $queryCount = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // The preview is an Inertia page; the compiled assets are not what is
        // under test (and a fresh checkout has no public/build).
        $this->withoutVite();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
        $this->organization = $this->teacher->personalOrganization();

        $this->travelTo(Carbon::parse('2026-10-19 18:30:00'));

        $this->seed(EntitlementsSeeder::class);
        $this->subscribeToBase($this->organization);

        $this->class = $this->asTenant(fn (): SchoolClass => SchoolClass::where('label', '7.º A')->firstOrFail());
    }

    // ------------------------------------------------------------- scaffolding

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback, ?Organization $organization = null): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization ?? $this->organization, $callback);
    }

    private function subscribeToBase(Organization $organization): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $organization->id],
            [
                'plan_id' => Plan::where('key', 'base')->firstOrFail()->id,
                'status' => SubscriptionStatus::Active,
                'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
                'ends_at' => null,
            ],
        );

        app(Entitlements::class)->flush();
    }

    private function disableModule(Organization $organization, string $moduleKey): void
    {
        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'module_id' => Module::where('key', $moduleKey)->firstOrFail()->getKey(),
            'enabled' => false,
            'reason' => 'Teste de override.',
        ]);

        app(Entitlements::class)->flush();
    }

    /**
     * The roster's enrollment ids, in class-number order: index 0 is student
     * number 1 (Ana Marques) and index 5 is number 6 (Filipe Andrade).
     *
     * @return list<int>
     */
    private function enrollmentIds(?SchoolClass $class = null): array
    {
        return $this->asTenant(fn (): array => ($class ?? $this->class)
            ->enrollments()->orderBy('class_number')->pluck('id')->map(fn ($id): int => (int) $id)->all());
    }

    /**
     * Registers a measure the way the application does (CreateIntervention),
     * with the target inferred from who it names: nobody is the whole class,
     * one is a student, several are a group.
     *
     * @param  list<int>  $enrollmentIds
     * @param  array<string, mixed>  $attributes
     * @param  list<array{level: string, code: string}>  $supportMeasures
     */
    private function measure(
        array $enrollmentIds,
        InterventionStatus $status = InterventionStatus::New,
        array $attributes = [],
        array $supportMeasures = [],
        ?SchoolClass $class = null,
    ): Intervention {
        $target = match (true) {
            $enrollmentIds === [] => InterventionTargetType::SchoolClass,
            count($enrollmentIds) === 1 => InterventionTargetType::Student,
            default => InterventionTargetType::Group,
        };

        return $this->asTenant(function () use ($enrollmentIds, $status, $attributes, $supportMeasures, $class, $target): Intervention {
            return app(CreateIntervention::class)->create(
                class: $class ?? $this->class,
                attributes: array_merge([
                    'enrollment_id' => count($enrollmentIds) === 1 ? $enrollmentIds[0] : null,
                    'target_type' => $target,
                    'intervention_type' => InterventionType::TutorialSupport,
                    'intervention_type_label' => InterventionType::TutorialSupport->label(),
                    'domain_relation' => InterventionDomainRelation::None,
                    'title' => InterventionType::TutorialSupport->label(),
                    'description_source' => InterventionDescriptionSource::Manual,
                    'status' => $status,
                    'started_on' => '2026-10-01',
                    'available_for_reports' => true,
                    'include_in_report' => true,
                ], $attributes),
                supportMeasures: $supportMeasures,
                participantIds: $enrollmentIds,
                createdBy: $this->teacher,
            );
        });
    }

    /**
     * A measure of a given type, stamped with that type's designation.
     *
     * @param  list<int>  $enrollmentIds
     * @param  array<string, mixed>  $attributes
     */
    private function typed(array $enrollmentIds, InterventionType $type, InterventionStatus $status = InterventionStatus::New, array $attributes = []): Intervention
    {
        return $this->measure($enrollmentIds, $status, array_merge([
            'intervention_type' => $type,
            'intervention_type_label' => $type->label(),
            'title' => $type->label(),
        ], $attributes));
    }

    private function showUrl(?SchoolClass $class = null, string $path = '', array $query = []): string
    {
        return '/reports/turma/'.($class ?? $this->class)->ulid.'/mapa-de-medidas'.$path
            .($query === [] ? '' : '?'.http_build_query($query));
    }

    /**
     * @param  array<string, string>  $query
     */
    private function show(array $query = [], ?User $as = null, ?SchoolClass $class = null): TestResponse
    {
        return $this->actingAs($as ?? $this->teacher)->get($this->showUrl($class, '', $query));
    }

    /**
     * @param  array<string, string>  $query
     */
    private function pdf(array $query = [], ?User $as = null, ?SchoolClass $class = null): TestResponse
    {
        return $this->actingAs($as ?? $this->teacher)->get($this->showUrl($class, '/pdf', $query));
    }

    /**
     * The Inertia props of the preview.
     *
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function props(array $query = [], ?SchoolClass $class = null): array
    {
        $props = [];

        $this->show($query, null, $class)
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$props): void {
                $page->component(self::COMPONENT);
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function document(array $query = [], ?SchoolClass $class = null): array
    {
        return $this->props($query, $class)['document'];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<string>
     */
    private function names(array $document): array
    {
        return array_column($document['students'], 'name');
    }

    /**
     * @param  array<string, mixed>  $student
     * @return list<string>
     */
    private function labels(array $student, string $column): array
    {
        return array_column($student[$column], 'label');
    }

    private function snapshotOfHistory(): string
    {
        return md5(collect(['interventions', 'intervention_support_measures', 'intervention_reviews', 'reports', 'intervention_enrollment'])
            ->map(fn (string $table): string => $table.':'.DB::table($table)->orderBy('id')->get()->toJson())
            ->implode('|'));
    }

    // ---------------------------------------------------- 1, 2, 24: who appears

    #[Test]
    public function only_students_with_measures_appear(): void
    {
        $ids = $this->enrollmentIds();

        $this->measure([$ids[0]]);
        $this->measure([$ids[2]], InterventionStatus::InProgress);

        $document = $this->document();

        $this->assertSame(['Ana Marques', 'Carolina Nunes'], $this->names($document));
        $this->assertSame(6, $document['summary']['students']);
        $this->assertSame(2, $document['summary']['students_with_measures']);
        $this->assertFalse($document['is_empty']);
    }

    #[Test]
    public function a_student_with_several_measures_appears_once(): void
    {
        $ids = $this->enrollmentIds();

        $this->typed([$ids[0]], InterventionType::TutorialSupport, InterventionStatus::InProgress);
        $this->typed([$ids[0]], InterventionType::ExtraTime, InterventionStatus::Concluded);
        $this->typed([$ids[0]], InterventionType::TimeManagementSupport, InterventionStatus::New);

        $document = $this->document();

        $this->assertCount(1, $document['students']);
        $this->assertCount(2, $document['students'][0]['implemented']);
        $this->assertCount(1, $document['students'][0]['planned']);
        $this->assertSame(1, $document['summary']['students_with_measures']);
    }

    #[Test]
    public function students_follow_the_class_number_not_the_order_the_measures_were_made(): void
    {
        $ids = $this->enrollmentIds();

        // Registered backwards on purpose.
        foreach (array_reverse($ids) as $id) {
            $this->measure([$id]);
        }

        $document = $this->document();

        $this->assertSame([1, 2, 3, 4, 5, 6], array_column($document['students'], 'number'));
        $this->assertSame(
            ['Ana Marques', 'Bruno Teixeira', 'Carolina Nunes', 'Diogo Ferreira', 'Eva Salgado', 'Filipe Andrade'],
            $this->names($document),
        );
    }

    // ------------------------------------------------------------ 3: the states

    #[Test]
    public function in_progress_concluded_and_suspended_are_implemented_with_their_own_state_and_planned_is_to_implement(): void
    {
        $ids = $this->enrollmentIds();

        $this->typed([$ids[0]], InterventionType::TutorialSupport, InterventionStatus::InProgress);
        $this->typed([$ids[0]], InterventionType::ExtraTime, InterventionStatus::Concluded);
        $this->typed([$ids[0]], InterventionType::AutonomyPromotion, InterventionStatus::Suspended);
        $this->typed([$ids[0]], InterventionType::TimeManagementSupport, InterventionStatus::New);

        $student = $this->document()['students'][0];

        $implemented = collect($student['implemented'])->pluck('status', 'label')->all();

        $this->assertEquals([
            InterventionType::TutorialSupport->label() => 'Em curso',
            InterventionType::ExtraTime->label() => 'Concluída',
            InterventionType::AutonomyPromotion->label() => 'Suspensa',
        ], $implemented);

        $this->assertSame([InterventionType::TimeManagementSupport->label()], $this->labels($student, 'planned'));
        // The column heading already says «a implementar»; there is no state to repeat.
        $this->assertNull($student['planned'][0]['status']);
    }

    // ----------------------------------------------- 4: what is left out, and said

    #[Test]
    public function cancelled_deleted_and_unavailable_measures_are_left_out_and_the_unavailable_ones_are_counted(): void
    {
        $ids = $this->enrollmentIds();

        // Ana: one visible measure, and three that must not be.
        $this->typed([$ids[0]], InterventionType::TutorialSupport, InterventionStatus::InProgress);
        $this->typed([$ids[0]], InterventionType::PsychopedagogicalSupport, InterventionStatus::Cancelled);
        $this->typed([$ids[0]], InterventionType::ExtraTime, InterventionStatus::InProgress)->delete();
        $this->typed([$ids[0]], InterventionType::TimeManagementSupport, InterventionStatus::InProgress, ['available_for_reports' => false]);
        // Unavailable AND cancelled: not in any column, so not in the footnote either.
        $this->typed([$ids[1]], InterventionType::AutonomyPromotion, InterventionStatus::Cancelled, ['available_for_reports' => false]);

        $document = $this->document();

        $this->assertSame(['Ana Marques'], $this->names($document));
        $this->assertSame([InterventionType::TutorialSupport->label()], $this->labels($document['students'][0], 'implemented'));
        $this->assertSame(
            '1 medida desta turma não está disponível para relatórios e não aparece neste mapa.',
            $document['excluded_note'],
        );

        $this->typed([$ids[2]], InterventionType::BehaviourRegulation, InterventionStatus::New, ['available_for_reports' => false]);

        $this->assertSame(
            '2 medidas desta turma não estão disponíveis para relatórios e não aparecem neste mapa.',
            $this->document()['excluded_note'],
        );
    }

    #[Test]
    public function the_footnote_counts_only_unavailable_measures_that_would_have_reached_this_map(): void
    {
        $ids = $this->enrollmentIds();

        $this->typed([$ids[0]], InterventionType::TutorialSupport, InterventionStatus::InProgress);

        // Kept out of reports, but nobody on today's roll would have shown it:
        // a single student who left, and a group whose members have all left.
        $this->typed([$ids[4]], InterventionType::ExtraTime, InterventionStatus::InProgress, ['available_for_reports' => false]);
        $this->typed([$ids[2], $ids[3]], InterventionType::SmallGroupSupport, InterventionStatus::New, ['available_for_reports' => false]);

        foreach ([$ids[2], $ids[3], $ids[4]] as $id) {
            $this->asTenant(fn () => Enrollment::whereKey($id)->update(['status' => EnrollmentStatus::Left]));
        }

        $this->assertNull($this->document()['excluded_note']);

        // Kept out and WOULD have reached the map: the whole class, and a single
        // student through the legacy column with no pivot row.
        $this->typed([], InterventionType::ClassIntegration, InterventionStatus::InProgress, ['available_for_reports' => false]);
        $legacy = $this->typed([$ids[1]], InterventionType::AutonomyPromotion, InterventionStatus::New, ['available_for_reports' => false]);
        $this->asTenant(fn () => $legacy->participants()->detach());

        $this->assertSame(
            '2 medidas desta turma não estão disponíveis para relatórios e não aparecem neste mapa.',
            $this->document()['excluded_note'],
        );
    }

    #[Test]
    public function there_is_no_footnote_when_nothing_was_left_out(): void
    {
        $this->measure([$this->enrollmentIds()[0]]);

        $this->assertNull($this->document()['excluded_note']);
    }

    // ------------------------------------------------------------- 5, 6, 7: filters

    #[Test]
    public function asking_only_for_the_implemented_drops_the_planned_column(): void
    {
        $ids = $this->enrollmentIds();

        $this->typed([$ids[0]], InterventionType::TutorialSupport, InterventionStatus::InProgress);
        $this->typed([$ids[1]], InterventionType::ExtraTime, InterventionStatus::New);

        $props = $this->props(['a_implementar' => '0']);
        $document = $props['document'];

        $this->assertSame(['implemented' => true, 'planned' => false], $document['columns']);
        $this->assertSame(['implemented' => true, 'planned' => false], $props['filters']);
        $this->assertSame(['Ana Marques'], $this->names($document));
        $this->assertNull($document['summary']['planned']);
        $this->assertSame(1, $document['summary']['implemented']);
        $this->assertStringNotContainsString('a implementar', $document['summary_line']);
        $this->assertSame([], $document['students'][0]['planned']);
    }

    #[Test]
    public function asking_only_for_the_planned_drops_the_implemented_column(): void
    {
        $ids = $this->enrollmentIds();

        $this->typed([$ids[0]], InterventionType::TutorialSupport, InterventionStatus::InProgress);
        $this->typed([$ids[1]], InterventionType::ExtraTime, InterventionStatus::New);

        $document = $this->document(['implementadas' => '0']);

        $this->assertSame(['implemented' => false, 'planned' => true], $document['columns']);
        $this->assertSame(['Bruno Teixeira'], $this->names($document));
        $this->assertNull($document['summary']['implemented']);
        $this->assertSame(1, $document['summary']['planned']);
        $this->assertStringNotContainsString('implementada', $document['summary_line']);
    }

    #[Test]
    public function a_filtered_out_columns_unavailable_measures_do_not_enter_the_footnote(): void
    {
        $ids = $this->enrollmentIds();

        $this->typed([$ids[0]], InterventionType::TutorialSupport, InterventionStatus::InProgress, ['available_for_reports' => false]);

        $this->assertNotNull($this->document()['excluded_note']);
        $this->assertNull($this->document(['implementadas' => '0'])['excluded_note']);
    }

    #[Test]
    public function asking_for_neither_column_shows_both_and_an_invalid_value_is_not_a_server_error(): void
    {
        $this->measure([$this->enrollmentIds()[0]]);

        $document = $this->document(['implementadas' => '0', 'a_implementar' => 'false']);

        $this->assertSame(['implemented' => true, 'planned' => true], $document['columns']);

        $this->assertSame(['implemented' => true, 'planned' => false], $this->props(['a_implementar' => 'false'])['filters']);

        $this->assertContains($this->show(['implementadas' => 'banana'])->getStatusCode(), [302, 422]);
        $this->assertContains($this->pdf(['a_implementar' => '2'])->getStatusCode(), [302, 422]);
    }

    // ------------------------------------------------------------------ 8: empty

    #[Test]
    public function a_class_without_measures_is_an_empty_document(): void
    {
        $document = $this->document();

        $this->assertTrue($document['is_empty']);
        $this->assertSame([], $document['students']);
        $this->assertSame(['implemented' => [], 'planned' => []], $document['class_wide']);
        $this->assertSame(
            'Não existem medidas educativas registadas para os alunos desta turma no contexto selecionado.',
            $document['empty_message'],
        );
        $this->assertSame('6 alunos · 0 com medidas · 0 implementadas · 0 a implementar', $document['summary_line']);
    }

    #[Test]
    public function a_class_with_only_cancelled_measures_is_an_empty_document(): void
    {
        $this->typed([$this->enrollmentIds()[0]], InterventionType::TutorialSupport, InterventionStatus::Cancelled);
        $this->typed([], InterventionType::ClassIntegration, InterventionStatus::Cancelled);

        $document = $this->document();

        $this->assertTrue($document['is_empty']);
        $this->assertSame([], $document['students']);
    }

    // ----------------------------------------------------------------- 9: summary

    #[Test]
    public function the_summary_counts_distinct_measures_and_students_with_measures(): void
    {
        $ids = $this->enrollmentIds();

        $this->typed([$ids[0]], InterventionType::TutorialSupport, InterventionStatus::InProgress);
        $this->typed([$ids[1]], InterventionType::ExtraTime, InterventionStatus::Concluded);
        // One group measure for three students counts ONCE.
        $this->typed([$ids[0], $ids[1], $ids[2]], InterventionType::SmallGroupSupport, InterventionStatus::New);
        // A class-wide measure counts, but makes nobody «com medidas».
        $this->typed([], InterventionType::ClassIntegration, InterventionStatus::New);

        $document = $this->document();

        $this->assertSame([
            'students' => 6,
            'students_with_measures' => 3,
            'implemented' => 2,
            'planned' => 2,
        ], $document['summary']);
        $this->assertSame('6 alunos · 3 com medidas · 2 implementadas · 2 a implementar', $document['summary_line']);
    }

    #[Test]
    public function the_summary_line_uses_the_singular_where_portuguese_does(): void
    {
        $this->typed([$this->enrollmentIds()[0]], InterventionType::TutorialSupport, InterventionStatus::InProgress);

        $this->assertSame(
            '6 alunos · 1 com medidas · 1 implementada · 0 a implementar',
            $this->document()['summary_line'],
        );
        $this->assertSame('6 alunos · 1 com medidas · 1 implementada', $this->document(['a_implementar' => '0'])['summary_line']);

        // One student on the roll: «1 aluno».
        foreach (array_slice($this->enrollmentIds(), 1) as $id) {
            $this->asTenant(fn () => Enrollment::whereKey($id)->update(['status' => EnrollmentStatus::Left]));
        }

        $this->assertStringStartsWith('1 aluno · ', $this->document()['summary_line']);
    }

    // --------------------------------------------------- 10, 11: group and class

    #[Test]
    public function a_group_measure_appears_for_each_active_participant_and_not_for_one_who_left(): void
    {
        $ids = $this->enrollmentIds();

        $this->typed([$ids[0], $ids[1], $ids[2]], InterventionType::SmallGroupSupport, InterventionStatus::InProgress);

        $this->asTenant(fn () => Enrollment::whereKey($ids[2])->update(['status' => EnrollmentStatus::TransferredOut]));

        $document = $this->document();

        $this->assertSame(['Ana Marques', 'Bruno Teixeira'], $this->names($document));

        foreach ($document['students'] as $student) {
            $this->assertTrue($student['implemented'][0]['group']);
        }

        // Counted once, and the student who left is not in the roll either.
        $this->assertSame(1, $document['summary']['implemented']);
        $this->assertSame(5, $document['summary']['students']);
    }

    #[Test]
    public function a_measure_whose_participants_have_all_left_reaches_nobody_and_is_not_counted(): void
    {
        $ids = $this->enrollmentIds();

        $this->typed([$ids[2], $ids[3]], InterventionType::SmallGroupSupport, InterventionStatus::InProgress);
        $this->typed([$ids[4]], InterventionType::TutorialSupport, InterventionStatus::InProgress);

        foreach ([$ids[2], $ids[3], $ids[4]] as $id) {
            $this->asTenant(fn () => Enrollment::whereKey($id)->update(['status' => EnrollmentStatus::Left]));
        }

        $document = $this->document();

        $this->assertTrue($document['is_empty']);
        $this->assertSame(0, $document['summary']['implemented']);
    }

    #[Test]
    public function an_individual_measure_is_not_mistaken_for_a_group_and_a_group_is_not_mistaken_for_the_whole_class(): void
    {
        $ids = $this->enrollmentIds();

        // Both a group and a class-wide measure have no `enrollment_id`: only
        // `target_type` tells them apart.
        $this->typed([$ids[0], $ids[1]], InterventionType::SmallGroupSupport, InterventionStatus::InProgress);
        $this->typed([$ids[3]], InterventionType::TutorialSupport, InterventionStatus::InProgress);

        $document = $this->document();

        $this->assertSame(['Ana Marques', 'Bruno Teixeira', 'Diogo Ferreira'], $this->names($document));
        $this->assertSame([], $document['class_wide']['implemented']);
        $this->assertFalse($document['students'][2]['implemented'][0]['group']);
    }

    #[Test]
    public function a_single_student_measure_without_pivot_rows_still_reaches_its_student_through_the_legacy_column(): void
    {
        $ids = $this->enrollmentIds();

        $measure = $this->typed([$ids[1]], InterventionType::TutorialSupport, InterventionStatus::InProgress);

        // An older row: the pivot was never written, only `enrollment_id`.
        $this->asTenant(fn () => $measure->participants()->detach());

        $this->assertSame(['Bruno Teixeira'], $this->names($this->document()));
    }

    #[Test]
    public function a_class_wide_measure_is_printed_once_in_its_own_block(): void
    {
        $ids = $this->enrollmentIds();

        $this->typed([], InterventionType::ClassIntegration, InterventionStatus::InProgress);
        $this->typed([], InterventionType::TimeManagementSupport, InterventionStatus::New);
        $this->typed([$ids[0]], InterventionType::TutorialSupport, InterventionStatus::InProgress);

        $document = $this->document();

        $this->assertSame([InterventionType::ClassIntegration->label()], array_column($document['class_wide']['implemented'], 'label'));
        $this->assertSame([InterventionType::TimeManagementSupport->label()], array_column($document['class_wide']['planned'], 'label'));
        // Only Ana is «com medidas»; the class-wide ones name nobody and are not repeated on her row.
        $this->assertSame(['Ana Marques'], $this->names($document));
        $this->assertSame([InterventionType::TutorialSupport->label()], $this->labels($document['students'][0], 'implemented'));
        $this->assertSame([], $document['students'][0]['planned']);
        $this->assertSame(1, $document['summary']['students_with_measures']);
        $this->assertFalse($document['is_empty']);
    }

    #[Test]
    public function a_class_with_only_class_wide_measures_is_not_empty(): void
    {
        $this->typed([], InterventionType::ClassIntegration, InterventionStatus::InProgress);

        $document = $this->document();

        $this->assertSame([], $document['students']);
        $this->assertFalse($document['is_empty']);
    }

    // ------------------------------------------------- 12: two classes, one student

    #[Test]
    public function a_student_in_two_classes_has_each_classs_measures_only_in_that_class(): void
    {
        $ids = $this->enrollmentIds();

        $otherClass = $this->asTenant(function (): SchoolClass {
            $class = SchoolClass::factory()->recycle($this->organization)->create([
                'academic_year_id' => $this->class->academic_year_id,
                'subject_id' => $this->class->subject_id,
                'label' => '7.º B',
            ]);
            $class->teachers()->syncWithoutDetaching([$this->teacher->id => ['role' => 'owner']]);

            return $class;
        });

        $student = $this->asTenant(fn (): Student => Enrollment::findOrFail($ids[0])->student);
        $otherEnrollment = $this->asTenant(fn (): Enrollment => app(StudentEnrollmentService::class)->enrollExisting($otherClass, $student));

        $this->typed([$ids[0]], InterventionType::TutorialSupport, InterventionStatus::InProgress);
        $this->measure(
            [$otherEnrollment->id],
            InterventionStatus::InProgress,
            ['intervention_type' => InterventionType::ExtraTime, 'intervention_type_label' => InterventionType::ExtraTime->label()],
            class: $otherClass,
        );

        $mapOfA = $this->document();
        $mapOfB = $this->document([], $otherClass);

        $this->assertSame('Ana Marques', $mapOfA['students'][0]['name']);
        $this->assertSame([InterventionType::TutorialSupport->label()], $this->labels($mapOfA['students'][0], 'implemented'));

        $this->assertSame('Ana Marques', $mapOfB['students'][0]['name']);
        $this->assertSame([InterventionType::ExtraTime->label()], $this->labels($mapOfB['students'][0], 'implemented'));
        $this->assertNull($mapOfB['students'][0]['number']);
        $this->assertSame('7.º B · '.$this->class->subject->name.' · Ano letivo '.$this->class->academicYear->label, $mapOfB['subtitle']);
    }

    // ------------------------------------------- 13, 14, 15: who may open it

    #[Test]
    public function a_teacher_of_another_organization_gets_404_on_the_screen_and_the_pdf(): void
    {
        $stranger = User::factory()->create();
        $this->subscribeToBase($stranger->personalOrganization());

        $this->show([], $stranger)->assertNotFound();
        $this->pdf([], $stranger)->assertNotFound();
    }

    #[Test]
    public function a_colleague_who_does_not_teach_the_class_gets_403_on_the_screen_and_the_pdf(): void
    {
        $colleague = User::factory()->create();
        $this->organization->members()->syncWithoutDetaching([$colleague->id => ['joined_at' => now()]]);

        $this->withSession(['organization_id' => $this->organization->id]);

        $this->show([], $colleague)->assertForbidden();
        $this->pdf([], $colleague)->assertForbidden();
    }

    #[Test]
    public function an_unknown_class_is_404_and_a_class_not_taught_is_403_and_extra_parameters_are_ignored(): void
    {
        $this->actingAs($this->teacher)->get('/reports/turma/01JZZZZZZZZZZZZZZZZZZZZZZZ/mapa-de-medidas')->assertNotFound();

        $notTaught = $this->asTenant(fn (): SchoolClass => SchoolClass::factory()->recycle($this->organization)->create(['label' => '9.º Z']));
        $this->show([], null, $notTaught)->assertForbidden();
        $this->pdf([], null, $notTaught)->assertForbidden();

        $ids = $this->enrollmentIds();
        $this->measure([$ids[0]]);

        // `class_id` is not a parameter of this document: the class is the URL's.
        $props = $this->props(['class_id' => (string) $notTaught->id, 'class' => $notTaught->ulid, 'foo' => 'bar']);

        $this->assertSame($this->class->ulid, $props['schoolClass']['ulid']);
        $this->assertSame(['Ana Marques'], $this->names($props['document']));
    }

    // ------------------------------------------------------- 16: minimised payload

    #[Test]
    public function the_payload_carries_only_what_is_printed(): void
    {
        $ids = $this->enrollmentIds();

        $private = [
            'description' => 'DESCRICAO-PRIVADA-8841',
            'motive_label' => 'MOTIVO-PRIVADO-8842',
            'objective' => 'OBJETIVO-PRIVADO-8843',
            'strategy_label' => 'ESTRATEGIA-PRIVADA-8844',
            'frequency' => 'FREQUENCIA-8845',
            'tracking_indicator' => 'INDICADOR-8846',
        ];

        $first = $this->typed([$ids[0]], InterventionType::TutorialSupport, InterventionStatus::InProgress, $private);
        $group = $this->typed([$ids[0], $ids[1]], InterventionType::SmallGroupSupport, InterventionStatus::New, $private);
        $classWide = $this->typed([], InterventionType::ClassIntegration, InterventionStatus::InProgress, $private);

        $this->asTenant(fn () => InterventionReview::create([
            'intervention_id' => $first->id,
            'reviewed_on' => '2026-10-10',
            'notes' => 'NOTA-ACOMPANHAMENTO-8847',
            'reviewed_by' => $this->teacher->id,
        ]));

        $student = $this->asTenant(fn (): Student => Enrollment::findOrFail($ids[0])->student);
        $this->asTenant(fn () => $student->identity->forceFill([
            'birth_date' => '2012-03-04',
            'school_number' => 'PROCESSO-8848',
        ])->save());

        $props = $this->props();
        $json = (string) json_encode($props, JSON_UNESCAPED_UNICODE);

        foreach (array_values($private) as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertStringNotContainsString('NOTA-ACOMPANHAMENTO-8847', $json);
        $this->assertStringNotContainsString('PROCESSO-8848', $json);
        $this->assertStringNotContainsString('2012-03-04', $json);
        $this->assertStringNotContainsString('birth_date', $json);
        $this->assertStringNotContainsString('school_number', $json);
        $this->assertStringNotContainsString('display_name_index', $json);

        $enrollmentUlids = $this->asTenant(fn (): array => Enrollment::query()->pluck('ulid')->all());
        $identifiers = [
            $first->ulid, $group->ulid, $classWide->ulid, $student->ulid ?? '', $student->pseudonym_code,
            ...$enrollmentUlids,
        ];

        foreach (array_filter($identifiers) as $identifier) {
            $this->assertStringNotContainsString((string) $identifier, $json);
        }

        // Exact shapes: a field added to the structure must be a decision.
        $document = $props['document'];

        $this->assertEqualsCanonicalizing([
            'title', 'subtitle', 'identity', 'issued_at', 'issued_label', 'columns', 'column_labels', 'summary',
            'summary_line', 'class_wide', 'students', 'excluded_note', 'criteria', 'empty_message', 'notice', 'is_empty',
        ], array_keys($document));
        $this->assertEqualsCanonicalizing(
            ['name', 'header_lines', 'footer_note', 'is_configured', 'logo_url', 'logo'],
            array_keys($document['identity']),
        );

        foreach ($document['students'] as $row) {
            $this->assertSame(['number', 'name', 'implemented', 'planned'], array_keys($row));

            foreach ([...$row['implemented'], ...$row['planned']] as $item) {
                $this->assertSame(['label', 'framing', 'status', 'group'], array_keys($item));
            }
        }

        foreach ([...$document['class_wide']['implemented'], ...$document['class_wide']['planned']] as $item) {
            $this->assertSame(['label', 'framing', 'status', 'group'], array_keys($item));
        }

        $this->assertSame(['ulid', 'label', 'subject', 'academic_year'], array_keys($props['schoolClass']));

        foreach ($props['classes'] as $option) {
            $this->assertSame(['ulid', 'label', 'subject', 'academic_year'], array_keys($option));
        }
    }

    // -------------------------------------------------------------------- 17: PDF

    #[Test]
    public function the_pdf_is_a_download_of_the_same_document_and_is_audited_without_names(): void
    {
        $ids = $this->enrollmentIds();

        $this->typed([$ids[0]], InterventionType::TutorialSupport, InterventionStatus::InProgress, ['description' => 'DESCRICAO-PRIVADA-9901']);
        $this->typed([$ids[1]], InterventionType::ExtraTime, InterventionStatus::New);
        // Not on the map: cancelled, unavailable, and a student with nothing.
        $this->typed([$ids[2]], InterventionType::PsychopedagogicalSupport, InterventionStatus::Cancelled);
        $this->typed([$ids[3]], InterventionType::TimeManagementSupport, InterventionStatus::InProgress, ['available_for_reports' => false]);

        $response = $this->pdf();

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment; filename="mapa-de-medidas-educativas-7o-a-', $disposition);
        $this->assertStringEndsWith('.pdf"', $disposition);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());

        // The template, fed the very structure the screen receives.
        $document = $this->asTenant(fn (): array => app(ClassMeasuresMap::class)->build($this->class, true, true));
        $html = View::make('reports.class-measures-map', ['document' => $document])->render();

        $this->assertSame($document['students'], $this->document()['students']);

        foreach (['Ana Marques', 'Bruno Teixeira', InterventionType::TutorialSupport->label(), InterventionType::ExtraTime->label(), 'Emitido a 19/10/2026, 18:30', $document['summary_line']] as $expected) {
            $this->assertStringContainsString(e($expected), $html);
        }

        foreach (['Carolina Nunes', 'Diogo Ferreira', InterventionType::PsychopedagogicalSupport->label(), InterventionType::TimeManagementSupport->label(), 'DESCRICAO-PRIVADA-9901'] as $absent) {
            $this->assertStringNotContainsString(e($absent), $html);
        }

        $this->assertStringContainsString(e($document['excluded_note']), $html);
        $this->assertStringContainsString(e($document['criteria']), $html);
        // The headings and the «not finalized» notice come with the structure, so the
        // paper cannot title or qualify the document differently from the screen.
        $this->assertStringContainsString(e($document['notice']), $html);
        $this->assertStringContainsString(e($document['column_labels']['implemented']), $html);
        $this->assertStringContainsString(e($document['column_labels']['planned']), $html);

        $audit = AuditEvent::withoutGlobalScopes()->where('event', 'reports.measures_map.exported')->get();

        $this->assertCount(1, $audit);
        $this->assertSame($this->teacher->id, $audit[0]->causer_id);
        // Without depending on key order: MySQL returns a JSON column's keys reordered.
        $this->assertSameJsonPayload(
            ['format' => 'pdf', 'columns' => ['implemented', 'planned'], 'students_with_measures' => 2, 'implemented' => 1, 'planned' => 1],
            $audit[0]->properties,
        );
        $this->assertStringNotContainsString('Ana Marques', (string) json_encode($audit[0]->properties).$audit[0]->summary);
        $this->assertSame('Mapa de Medidas Educativas de 7.º A exportado em PDF.', $audit[0]->summary);
    }

    #[Test]
    public function the_pdf_honours_the_column_filters(): void
    {
        $ids = $this->enrollmentIds();

        $this->typed([$ids[0]], InterventionType::TutorialSupport, InterventionStatus::InProgress);
        $this->typed([$ids[1]], InterventionType::ExtraTime, InterventionStatus::New);

        $this->pdf(['a_implementar' => '0'])->assertOk();

        $event = AuditEvent::withoutGlobalScopes()->where('event', 'reports.measures_map.exported')->firstOrFail();

        $this->assertSame(['implemented'], $event->properties['columns']);
        $this->assertNull($event->properties['planned']);
        $this->assertSame(1, $event->properties['implemented']);
    }

    #[Test]
    public function an_empty_map_still_prints_with_its_explanation_and_no_table(): void
    {
        $document = $this->asTenant(fn (): array => app(ClassMeasuresMap::class)->build($this->class, true, true));
        $html = View::make('reports.class-measures-map', ['document' => $document])->render();

        $this->assertStringContainsString(e($document['empty_message']), $html);
        $this->assertStringNotContainsString('<table class="map"', $html);
        $this->pdf()->assertOk();
    }

    // --------------------------------------------------------------- 18: history

    #[Test]
    public function producing_the_document_changes_nothing_in_the_history_it_reads(): void
    {
        $ids = $this->enrollmentIds();

        $measure = $this->typed([$ids[0]], InterventionType::TutorialSupport, InterventionStatus::InProgress);
        $this->typed([$ids[1], $ids[2]], InterventionType::SmallGroupSupport, InterventionStatus::New);
        $this->asTenant(fn () => InterventionReview::create([
            'intervention_id' => $measure->id,
            'reviewed_on' => '2026-10-10',
            'notes' => 'Nota de acompanhamento',
            'reviewed_by' => $this->teacher->id,
        ]));

        $before = $this->snapshotOfHistory();

        $this->show()->assertOk();
        $this->pdf()->assertOk();

        $this->assertSame($before, $this->snapshotOfHistory());
        $this->assertSame(0, $this->asTenant(fn (): int => DB::table('reports')->count()));
    }

    #[Test]
    public function a_legacy_row_without_a_designation_is_never_printed_as_its_placeholder_title(): void
    {
        $ids = $this->enrollmentIds();

        $this->measure([$ids[0]], InterventionStatus::InProgress, [
            'intervention_type' => null,
            'intervention_type_label' => null,
            'title' => 'Legado sem dominio',
        ]);

        $student = $this->document()['students'][0];

        $this->assertSame(['Medida sem tipo registado'], $this->labels($student, 'implemented'));
        $this->assertStringNotContainsString('Legado sem dominio', (string) json_encode($student));
    }

    #[Test]
    public function an_other_or_untyped_measure_never_prints_the_teachers_own_words(): void
    {
        $ids = $this->enrollmentIds();

        // The teacher's own words in every field a fallback could reach for: a free
        // strategy, a title, a description. A strategy can say why a child needs it.
        $this->measure([$ids[0]], InterventionStatus::InProgress, [
            'intervention_type' => InterventionType::Other,
            'intervention_type_label' => InterventionType::Other->label(),
            'title' => 'TITULO-LIVRE-OUTRO-7731',
            'strategy_label' => 'ESTRATEGIA-LIVRE-7731 por causa do diagnóstico',
            'description' => 'DESCRICAO-LIVRE-7731',
        ]);
        $this->measure([$ids[1]], InterventionStatus::InProgress, [
            'intervention_type' => InterventionType::Other,
            'intervention_type_label' => InterventionType::Other->label(),
            'title' => 'Legado sem dominio',
        ]);
        // An untyped row whose title IS meaningful prose — still not quoted.
        $this->measure([$ids[2]], InterventionStatus::New, [
            'intervention_type' => null,
            'intervention_type_label' => null,
            'title' => 'TITULO-LIVRE-SEM-TIPO-7731',
            'strategy_label' => 'ESTRATEGIA-LIVRE-SEM-TIPO-7731',
        ]);
        // An imported legal measure with no catalogue type of its own keeps the
        // legal designation it was stamped with: that is a catalogue name.
        $this->measure([$ids[3]], InterventionStatus::InProgress, [
            'intervention_type' => InterventionType::Other,
            'intervention_type_label' => SupportMeasureCode::CurricularEnrichment->label(),
            'title' => SupportMeasureCode::CurricularEnrichment->label(),
        ]);

        $props = $this->props();
        $document = $props['document'];

        $this->assertSame(['Outra medida'], $this->labels($document['students'][0], 'implemented'));
        $this->assertSame(['Outra medida'], $this->labels($document['students'][1], 'implemented'));
        $this->assertSame(['Medida sem tipo registado'], $this->labels($document['students'][2], 'planned'));
        $this->assertSame([SupportMeasureCode::CurricularEnrichment->label()], $this->labels($document['students'][3], 'implemented'));

        $html = $this->asTenant(fn (): string => View::make('reports.class-measures-map', [
            'document' => app(ClassMeasuresMap::class)->build($this->class, true, true),
        ])->render());
        $json = (string) json_encode($props);

        foreach (['7731', 'Legado sem dominio', 'diagnóstico'] as $private) {
            $this->assertStringNotContainsString($private, $json, "«{$private}» chegou às props.");
            $this->assertStringNotContainsString($private, $html, "«{$private}» chegou ao PDF.");
        }
    }

    #[Test]
    public function the_designation_stamped_at_the_time_is_what_is_printed_not_the_current_one(): void
    {
        $this->measure([$this->enrollmentIds()[0]], InterventionStatus::InProgress, [
            'intervention_type' => InterventionType::LearningReinforcement,
            'intervention_type_label' => 'Reforço das aprendizagens',
            'title' => 'Reforço das aprendizagens',
        ]);

        $this->assertNotSame('Reforço das aprendizagens', InterventionType::LearningReinforcement->label());
        $this->assertSame(['Reforço das aprendizagens'], $this->labels($this->document()['students'][0], 'implemented'));
    }

    // ----------------------------------------------------------------- 19: framing

    #[Test]
    public function a_measure_named_like_its_legal_measure_prints_only_the_level(): void
    {
        $this->measure([$this->enrollmentIds()[0]], InterventionStatus::InProgress, [], [
            ['level' => SupportMeasureLevel::Selective->value, 'code' => SupportMeasureCode::TutorialSupport->value],
        ]);

        $item = $this->document()['students'][0]['implemented'][0];

        $this->assertSame('Apoio tutorial', $item['label']);
        $this->assertSame('Medida seletiva', $item['framing']);
    }

    #[Test]
    public function every_legal_measure_of_the_pivot_is_printed_with_the_stored_level(): void
    {
        $this->typed([$this->enrollmentIds()[0]], InterventionType::IndividualSupport, InterventionStatus::InProgress);

        $measure = Intervention::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->asTenant(function () use ($measure): void {
            $measure->supportMeasures()->create([
                'support_measure_level' => SupportMeasureLevel::Universal,
                'support_measure_code' => SupportMeasureCode::PedagogicalDifferentiation,
                'legal_mapping_source' => LegalMappingSource::Manual,
            ]);
            // Stored as «additional» although the framework reads this code as
            // selective: the level the teacher confirmed is what is printed.
            $measure->supportMeasures()->create([
                'support_measure_level' => SupportMeasureLevel::Additional,
                'support_measure_code' => SupportMeasureCode::PsychopedagogicalSupport,
                'legal_mapping_source' => LegalMappingSource::Manual,
            ]);
        });

        $item = $this->document()['students'][0]['implemented'][0];

        $this->assertSame(
            'Medida universal — Diferenciação pedagógica; Medida adicional — Apoio psicopedagógico',
            $item['framing'],
        );
    }

    #[Test]
    public function a_measure_without_pivot_rows_falls_back_to_the_pair_on_the_intervention_and_an_unframed_one_has_none(): void
    {
        $ids = $this->enrollmentIds();

        $this->measure([$ids[0]], InterventionStatus::InProgress, [
            'support_measure_level' => SupportMeasureLevel::Selective,
            'support_measure_code' => SupportMeasureCode::TutorialSupport,
            'legal_mapping_source' => LegalMappingSource::Manual,
        ]);
        // A level with no confirmed framing is a suggestion that was never settled.
        $this->typed([$ids[1]], InterventionType::ExtraTime, InterventionStatus::InProgress, [
            'support_measure_level' => SupportMeasureLevel::Selective,
            'support_measure_code' => SupportMeasureCode::TutorialSupport,
            'legal_mapping_source' => null,
        ]);

        $document = $this->document();

        $this->assertSame('Medida seletiva', $document['students'][0]['implemented'][0]['framing']);
        $this->assertNull($document['students'][1]['implemented'][0]['framing']);
    }

    #[Test]
    public function items_are_ordered_by_level_then_name_with_unframed_ones_last(): void
    {
        $ids = $this->enrollmentIds();

        $this->typed([$ids[0]], InterventionType::IndividualSupport, InterventionStatus::New);
        $this->measure([$ids[0]], InterventionStatus::New, [
            'intervention_type' => InterventionType::PsychopedagogicalSupport,
            'intervention_type_label' => InterventionType::PsychopedagogicalSupport->label(),
        ], [['level' => 'selective', 'code' => 'psychopedagogical_support']]);
        $this->measure([$ids[0]], InterventionStatus::New, [
            'intervention_type' => InterventionType::PedagogicalDifferentiation,
            'intervention_type_label' => InterventionType::PedagogicalDifferentiation->label(),
        ], [['level' => 'universal', 'code' => 'pedagogical_differentiation']]);
        $this->measure([$ids[0]], InterventionStatus::New, [
            'intervention_type' => InterventionType::TutorialSupport,
            'intervention_type_label' => InterventionType::TutorialSupport->label(),
        ], [['level' => 'selective', 'code' => 'tutorial_support']]);

        $this->assertSame(
            ['Diferenciação pedagógica', 'Apoio psicopedagógico', 'Apoio tutorial', 'Apoio individualizado'],
            $this->labels($this->document()['students'][0], 'planned'),
        );
    }

    // -------------------------------------------------------------------- 20: N+1

    #[Test]
    public function the_number_of_queries_does_not_grow_with_the_students_and_measures(): void
    {
        $ids = $this->enrollmentIds();

        DB::listen(function (): void {
            $this->queryCount++;
        });

        $this->measure([$ids[0]], InterventionStatus::InProgress);

        // Warm-up: whatever a first request caches is not what is being measured.
        $this->show()->assertOk();

        $this->queryCount = 0;
        $this->show()->assertOk();
        $queriesForOne = $this->queryCount;

        $this->typed([$ids[0]], InterventionType::ExtraTime, InterventionStatus::Concluded, []);
        foreach (array_slice($ids, 1) as $position => $id) {
            $this->typed([$id], InterventionType::cases()[$position + 2], InterventionStatus::InProgress);
        }
        $this->measure([$ids[0], $ids[1], $ids[2]], InterventionStatus::New, [
            'intervention_type' => InterventionType::SmallGroupSupport,
            'intervention_type_label' => InterventionType::SmallGroupSupport->label(),
        ], [['level' => 'selective', 'code' => 'tutorial_support'], ['level' => 'universal', 'code' => 'pedagogical_differentiation']]);
        $this->typed([], InterventionType::ClassIntegration, InterventionStatus::InProgress);
        $this->typed([$ids[3]], InterventionType::BehaviourRegulation, InterventionStatus::New, ['available_for_reports' => false]);

        $this->queryCount = 0;
        $props = $this->props();
        $queriesForMany = $this->queryCount;

        $this->assertCount(6, $props['document']['students']);
        $this->assertSame(
            $queriesForOne,
            $queriesForMany,
            "One student and one measure cost {$queriesForOne} queries; six students, many measures, a group and a class-wide one cost {$queriesForMany}.",
        );
    }

    // -------------------------------------------------------------- 21: entitlements

    #[Test]
    public function the_base_plan_is_enough(): void
    {
        $this->show()->assertOk();
        $this->pdf()->assertOk();
        $this->actingAs($this->teacher)->get('/reports/turma/mapa-de-medidas')->assertOk();
    }

    #[Test]
    public function without_the_reports_module_the_document_is_forbidden(): void
    {
        $this->disableModule($this->organization, 'reports');

        $this->show()->assertForbidden();
        $this->pdf()->assertForbidden();
    }

    #[Test]
    public function without_the_interventions_module_the_document_is_forbidden(): void
    {
        $this->disableModule($this->organization, 'interventions');

        $this->show()->assertForbidden();
        $this->pdf()->assertForbidden();
        $this->actingAs($this->teacher)->get('/reports/turma/mapa-de-medidas')->assertForbidden();
    }

    // -------------------------------------------------------------------- 22: no AI

    #[Test]
    public function nothing_is_sent_anywhere(): void
    {
        Http::fake();

        $this->measure([$this->enrollmentIds()[0]], InterventionStatus::InProgress);

        $this->show()->assertOk();
        $this->pdf()->assertOk();

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------- 23: index

    #[Test]
    public function the_picker_lists_only_the_classes_the_teacher_teaches(): void
    {
        $this->asTenant(fn () => SchoolClass::factory()->recycle($this->organization)->create(['label' => '9.º Z']));

        $props = [];

        $this->actingAs($this->teacher)
            ->get('/reports/turma/mapa-de-medidas')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$props): void {
                $page->component(self::COMPONENT);
                $props = $page->toArray()['props'];
            });

        $this->assertNull($props['schoolClass']);
        $this->assertNull($props['document']);
        $this->assertSame(['implemented' => true, 'planned' => true], $props['filters']);
        $this->assertCount(1, $props['classes']);
        $this->assertSame('7.º A', $props['classes'][0]['label']);
        $this->assertSame($this->class->ulid, $props['classes'][0]['ulid']);
        $this->assertSame(['ulid', 'label', 'subject', 'academic_year'], array_keys($props['classes'][0]));
    }

    #[Test]
    public function the_reports_index_says_whether_the_map_can_be_opened(): void
    {
        $this->actingAs($this->teacher)
            ->get('/reports')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('reports/Index')
                ->where('canOpenMeasuresMap', true));

        $this->disableModule($this->organization, 'interventions');

        $this->actingAs($this->teacher)
            ->get('/reports')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canOpenMeasuresMap', false));
    }

    // --------------------------------------------------------- the preview's other props

    #[Test]
    public function the_preview_names_the_class_the_year_and_the_moment_it_was_issued(): void
    {
        $props = $this->props();
        $document = $props['document'];

        $this->assertSame('Mapa de Medidas Educativas', $document['title']);
        $this->assertSame(
            '7.º A · '.$this->class->subject->name.' · Ano letivo '.$this->class->academicYear->label,
            $document['subtitle'],
        );
        $this->assertSame('Emitido a 19/10/2026, 18:30', $document['issued_label']);
        $this->assertSame(['ulid' => $this->class->ulid, 'label' => '7.º A', 'subject' => $this->class->subject->name, 'academic_year' => $this->class->academicYear->label], $props['schoolClass']);
        $this->assertNull($document['identity']['logo']);
        $this->assertNull($document['identity']['logo_url']);
        $this->assertSame(
            'Implementadas: medidas em curso, concluídas ou suspensas. A implementar: medidas planeadas. Não inclui medidas canceladas.',
            $document['criteria'],
        );
    }

    #[Test]
    public function a_student_without_an_identity_is_named_as_such_rather_than_skipped(): void
    {
        $ids = $this->enrollmentIds();

        $this->measure([$ids[0]], InterventionStatus::InProgress);

        $this->asTenant(function () use ($ids): void {
            Enrollment::findOrFail($ids[0])->student->identity()->delete();
        });

        $this->assertSame(['(sem identidade)'], $this->names($this->document()));
    }

    #[Test]
    public function the_json_shape_of_the_item_is_what_the_screen_draws(): void
    {
        $this->measure([$this->enrollmentIds()[0]], InterventionStatus::InProgress, [], [
            ['level' => 'selective', 'code' => 'tutorial_support'],
        ]);

        $this->show()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('document.students.0', fn (AssertableJson $student) => $student
                ->where('number', 1)
                ->where('name', 'Ana Marques')
                ->has('implemented.0', fn (AssertableJson $item) => $item
                    ->where('label', 'Apoio tutorial')
                    ->where('framing', 'Medida seletiva')
                    ->where('status', 'Em curso')
                    ->where('group', false))
                ->where('planned', [])));
    }
}
