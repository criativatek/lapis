<?php

namespace Tests\Feature\Lessons;

use App\Models\AcademicYear;
use App\Models\CalendarEvent;
use App\Models\CalendarEventType;
use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\Module;
use App\Models\OrganizationModuleOverride;
use App\Models\OrganizationSubscription;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * O contrato da linha de aula (a MESMA na semana e na vista da turma) e as
 * props `classes` / `classView` de `GET /lessons`.
 *
 * Calendário de outubro de 2026: as segundas-feiras são 05, 12, 19 e 26; as
 * quintas 08, 15, 22 e 29. O ano letivo das fixtures vai de 2026-09-01 a
 * 2027-06-30.
 */
class WeekRowAndClassViewTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    private const ROW_KEYS = [
        'absent_count', 'attendance_recorded', 'can_clear_summary', 'can_delete', 'class_group_id', 'class_group_label',
        'context_label', 'day_events', 'ends_at', 'has_summary', 'identity_tone', 'lesson_number', 'outcome',
        'outcome_label', 'school_class', 'starts_at', 'status', 'status_label', 'subject', 'summary',
        'summary_reviewed', 'summary_version', 'ulid',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLessonFixtures();
        $this->schoolClass->update(['label' => '7.º A']);
        Carbon::setTestNow('2026-10-06 10:00:00');
    }

    // ------------------------------------------------------------ linha da semana

    #[Test]
    public function the_week_row_has_exactly_the_agreed_keys_and_values(): void
    {
        $group = $this->makeGroup('T1');
        $lesson = $this->makeLesson([
            'starts_at' => '2026-10-08 09:30:00',
            'class_group_id' => $group->id,
            'lesson_number' => 3,
            'status' => LessonStatus::Taught,
        ]);
        $content = str_repeat('Texto longo do sumário. ', 20);
        $this->inTenant($this->organization, fn () => $lesson->summary()->create([
            'content' => "  {$content}  ",
            'private_notes' => 'Nunca viaja na linha.',
            'reviewed_at' => '2026-10-08 12:00:00',
        ]));
        $this->event($this->schoolClass, ['title' => 'Visita de estudo', 'starts_at' => '10:00', 'ends_at' => '12:00']);

        $row = $this->week('2026-10-05')['lessons'][0];

        $keys = array_keys($row);
        sort($keys);
        $this->assertSame(self::ROW_KEYS, $keys);
        $this->assertArrayNotHasKey('summary_excerpt', $row);
        $this->assertArrayNotHasKey('summary_full', $row);
        $this->assertStringNotContainsString('Nunca viaja', json_encode($row, JSON_THROW_ON_ERROR));

        $this->assertSame($lesson->ulid, $row['ulid']);
        $this->assertSame(trim($content), $row['summary']);
        $this->assertTrue($row['has_summary']);
        $this->assertTrue($row['summary_reviewed']);
        $this->assertSame($this->summaryVersion($lesson), $row['summary_version']);
        $this->assertSame($group->id, $row['class_group_id']);
        $this->assertSame('T1', $row['class_group_label']);
        $this->assertSame('7.º A · T1', $row['context_label']);
        $this->assertSame(['ulid' => $this->schoolClass->ulid, 'label' => '7.º A', 'is_support_class' => false], $row['school_class']);
        $this->assertSame('blue', $row['identity_tone']);
        $this->assertSame('taught', $row['status']);
        $this->assertSame(3, $row['lesson_number']);
        $this->assertCount(1, $row['day_events']);
        $this->assertSame(['ulid', 'title', 'starts_at', 'ends_at', 'all_day', 'notes', 'type_label'], array_keys($row['day_events'][0]));
        $this->assertSame('10:00', $row['day_events'][0]['starts_at']);
    }

    #[Test]
    public function an_empty_summary_is_null_and_not_reviewed(): void
    {
        $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);

        $row = $this->week('2026-10-05')['lessons'][0];

        $this->assertNull($row['summary']);
        $this->assertFalse($row['has_summary']);
        $this->assertFalse($row['summary_reviewed']);
        $this->assertSame([], $row['day_events']);
    }

    #[Test]
    public function a_support_class_is_flagged_in_the_row(): void
    {
        $this->schoolClass->update(['is_support_class' => true]);
        $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);

        $this->assertTrue($this->week('2026-10-05')['lessons'][0]['school_class']['is_support_class']);
    }

    #[Test]
    public function day_events_only_travel_when_the_calendar_can_be_read(): void
    {
        $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);
        $this->event($this->schoolClass, ['title' => 'Reunião']);

        $this->assertCount(1, $this->week('2026-10-05')['lessons'][0]['day_events']);

        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $this->organization->id,
            'module_id' => Module::where('key', 'calendar')->firstOrFail()->getKey(),
            'enabled' => false,
            'reason' => 'Teste de entitlement.',
        ]);
        app(Entitlements::class)->flush();

        $this->assertSame([], $this->week('2026-10-05')['lessons'][0]['day_events']);
    }

    #[Test]
    public function another_teachers_events_never_appear_in_my_week(): void
    {
        $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);
        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);
        $this->inTenant($this->organization, fn () => $this->schoolClass->teachers()->attach($colleague, ['role' => 'co_teacher']));
        $this->event($this->schoolClass, ['title' => 'Do colega'], $colleague);

        $this->assertSame([], $this->week('2026-10-05')['lessons'][0]['day_events']);
    }

    // ------------------------------------------------------------------ classes

    #[Test]
    public function classes_lists_every_class_of_the_teacher_in_the_year_ordered_by_label(): void
    {
        $archived = $this->extraClass('7.º B');
        $archived->update(['archived_at' => '2026-09-20 10:00:00']);
        $this->extraClass('6.º C');
        $this->makeGroup('T2');
        $this->makeGroup('T1');
        $archivedGroup = $this->makeGroup('Velho');
        $archivedGroup->update(['archived_at' => now()]);
        // Uma turma de outro professor não aparece.
        $this->extraClass('9.º Z', teacher: $this->colleague());

        $classes = $this->week('2026-10-05')['classes'];

        $this->assertSame(['6.º C', '7.º A', '7.º B'], array_column($classes, 'label'));
        $this->assertSame(['ulid', 'label', 'subject', 'is_support_class', 'archived', 'identity_tone', 'groups'], array_keys($classes[1]));
        $this->assertSame([false, false, true], array_column($classes, 'archived'));
        $this->assertSame(['T2', 'T1'], array_column($classes[1]['groups'], 'label'));
        $this->assertSame(['id', 'label'], array_keys($classes[1]['groups'][0]));
        $this->assertSame('blue', $classes[1]['identity_tone']);
    }

    #[Test]
    public function classes_is_empty_and_class_view_is_null_without_classes(): void
    {
        $lonely = User::factory()->create();
        $organization = $lonely->personalOrganization();
        $this->subscribeToPro($organization);
        $this->inTenant($organization, fn () => AcademicYear::factory()->recycle($organization)->active()->create(['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']));

        $this->actingAs($lonely)->withSession(['organization_id' => $organization->id])
            ->get('/lessons?week=2026-10-05&view=turma')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('classes', [])->where('classView', null));
    }

    // ---------------------------------------------------------------- classView

    #[Test]
    public function class_view_is_null_unless_the_class_view_is_requested(): void
    {
        $this->assertNull($this->week('2026-10-05')['classView']);
        $this->assertNull($this->week('2026-10-05', ['view' => 'semana'])['classView']);
    }

    #[Test]
    public function the_range_ends_on_the_sunday_of_the_selected_week_and_goes_back_whole_weeks(): void
    {
        $range = fn (string $week, string $key): array => $this->classView($week, ['range' => $key])['range'];

        $this->assertSame(['key' => '1', 'start' => '2026-10-05', 'end' => '2026-10-11', 'clamped' => false], $range('2026-10-08', '1'));
        $this->assertSame(['key' => '2', 'start' => '2026-09-28', 'end' => '2026-10-11', 'clamped' => false], $range('2026-10-08', '2'));
        $this->assertSame(['key' => '4', 'start' => '2026-09-14', 'end' => '2026-10-11', 'clamped' => false], $range('2026-10-08', '4'));
        $this->assertSame(['key' => 'ano', 'start' => '2026-09-01', 'end' => '2026-10-11', 'clamped' => false], $range('2026-10-08', 'ano'));
    }

    #[Test]
    public function the_range_is_clamped_to_the_start_of_the_academic_year(): void
    {
        // Segunda 2026-09-07; quatro semanas recuariam para 2026-08-17.
        $this->assertSame(
            ['key' => '4', 'start' => '2026-09-01', 'end' => '2026-09-13', 'clamped' => true],
            $this->classView('2026-09-07', ['range' => '4'])['range'],
        );
        // Dentro do ano, sem corte.
        $this->assertFalse($this->classView('2026-09-28', ['range' => '4'])['range']['clamped']);
    }

    #[Test]
    public function the_default_range_is_one_week(): void
    {
        $this->assertSame('1', $this->classView('2026-10-08')['range']['key']);
    }

    #[Test]
    public function the_class_view_lists_the_classs_lessons_in_the_range_in_chronological_order(): void
    {
        $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);
        $this->makeLesson(['starts_at' => '2026-10-01 09:30:00']);
        $this->makeLesson(['starts_at' => '2026-09-10 09:30:00']);
        $this->makeLesson(['starts_at' => '2026-10-15 09:30:00']);

        $view = $this->classView('2026-10-05', ['range' => '2']);

        $this->assertSame(
            ['2026-10-01', '2026-10-08'],
            array_map(fn (array $row): string => substr($row['starts_at'], 0, 10), $view['lessons']),
        );
    }

    #[Test]
    public function the_week_and_the_class_view_build_identical_rows(): void
    {
        $lesson = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);
        $this->inTenant($this->organization, fn () => $lesson->summary()->create(['content' => 'Igual nas duas.']));
        $this->event($this->schoolClass, ['title' => 'Reunião']);

        $weekRow = $this->week('2026-10-05')['lessons'][0];
        $classRow = $this->classView('2026-10-05')['lessons'][0];

        $this->assertSame($weekRow, $classRow);
    }

    #[Test]
    public function the_group_filter_narrows_the_lessons(): void
    {
        $t1 = $this->makeGroup('T1');
        $t2 = $this->makeGroup('T2');
        $whole = $this->makeLesson(['starts_at' => '2026-10-06 09:30:00']);
        $first = $this->makeLesson(['starts_at' => '2026-10-07 09:30:00', 'class_group_id' => $t1->id]);
        $second = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00', 'class_group_id' => $t2->id]);

        $ulids = fn (string $group): array => array_column($this->classView('2026-10-05', ['group' => $group])['lessons'], 'ulid');

        $this->assertSame([$whole->ulid, $first->ulid, $second->ulid], $ulids('todos'));
        $this->assertSame([$whole->ulid], $ulids('inteira'));
        $this->assertSame([$first->ulid], $ulids((string) $t1->id));
        $this->assertSame('inteira', $this->classView('2026-10-05', ['group' => 'inteira'])['group']);
        $this->assertSame((string) $t1->id, $this->classView('2026-10-05', ['group' => (string) $t1->id])['group']);
    }

    #[Test]
    public function an_unknown_group_or_one_of_another_class_counts_as_all(): void
    {
        $other = $this->extraClass('8.º B');
        $foreignGroup = $this->makeGroup('Alheio', $other);
        $lesson = $this->makeLesson(['starts_at' => '2026-10-06 09:30:00']);

        foreach (['999999', (string) $foreignGroup->id, 'abc'] as $group) {
            $view = $this->classView('2026-10-05', ['group' => $group, 'class' => $this->schoolClass->ulid]);
            $this->assertSame('todos', $view['group']);
            $this->assertSame([$lesson->ulid], array_column($view['lessons'], 'ulid'));
        }
    }

    #[Test]
    public function previous_lanes_are_same_lane_only_and_skip_empty_summaries(): void
    {
        $t1 = $this->makeGroup('T1');
        $t2 = $this->makeGroup('T2');
        $wholeOld = $this->makeLesson(['starts_at' => '2026-09-15 09:30:00']);
        $wholeNewer = $this->makeLesson(['starts_at' => '2026-09-22 09:30:00']);
        $t1Last = $this->makeLesson(['starts_at' => '2026-09-23 09:30:00', 'class_group_id' => $t1->id]);
        $t2Real = $this->makeLesson(['starts_at' => '2026-09-16 09:30:00', 'class_group_id' => $t2->id]);
        $t2Empty = $this->makeLesson(['starts_at' => '2026-09-24 09:30:00', 'class_group_id' => $t2->id]);
        // Dentro do intervalo, ou depois dele: nunca «anterior».
        $inRange = $this->makeLesson(['starts_at' => '2026-10-06 09:30:00']);

        $this->summary($wholeOld, 'Turma velha.');
        $this->summary($wholeNewer, 'Turma recente.');
        $this->summary($t1Last, 'T1.');
        $this->summary($t2Real, 'T2 real.');
        $this->summary($t2Empty, '   ');
        $this->summary($inRange, 'Dentro do intervalo.');

        $previous = $this->classView('2026-10-05')['previous'];

        $this->assertSame([null, $t1->id, $t2->id], array_column($previous, 'group_id'));
        $this->assertSame([null, 'T1', 'T2'], array_column($previous, 'group_label'));
        $this->assertSame($wholeNewer->ulid, $previous[0]['lesson']['ulid']);
        $this->assertSame($t1Last->ulid, $previous[1]['lesson']['ulid']);
        $this->assertSame($t2Real->ulid, $previous[2]['lesson']['ulid']);
        // A linha de «anterior» é a mesma estrutura de qualquer outra.
        $this->assertSame('Turma recente.', $previous[0]['lesson']['summary']);
    }

    #[Test]
    public function previous_lanes_follow_the_group_filter_and_are_null_without_a_match(): void
    {
        $t1 = $this->makeGroup('T1');
        $this->makeGroup('T2');
        $whole = $this->makeLesson(['starts_at' => '2026-09-22 09:30:00']);
        $this->summary($whole, 'Turma.');

        $inteira = $this->classView('2026-10-05', ['group' => 'inteira'])['previous'];
        $this->assertCount(1, $inteira);
        $this->assertNull($inteira[0]['group_id']);
        $this->assertSame($whole->ulid, $inteira[0]['lesson']['ulid']);

        // T1 nunca recebe o sumário da turma inteira por falta de melhor.
        $onlyT1 = $this->classView('2026-10-05', ['group' => (string) $t1->id])['previous'];
        $this->assertCount(1, $onlyT1);
        $this->assertSame($t1->id, $onlyT1[0]['group_id']);
        $this->assertSame('T1', $onlyT1[0]['group_label']);
        $this->assertNull($onlyT1[0]['lesson']);
    }

    #[Test]
    public function the_previous_lookup_is_strictly_before_the_range_start(): void
    {
        $edge = $this->makeLesson(['starts_at' => '2026-10-04 23:00:00']);
        $onStart = $this->makeLesson(['starts_at' => '2026-10-05 00:00:00']);
        $this->summary($edge, 'Domingo.');
        $this->summary($onStart, 'Segunda cedo.');

        $previous = $this->classView('2026-10-05')['previous'];

        $this->assertSame($edge->ulid, $previous[0]['lesson']['ulid']);
    }

    #[Test]
    public function a_class_the_teacher_does_not_teach_falls_back_to_the_first_class(): void
    {
        $this->extraClass('5.º A');
        $foreign = $this->extraClass('9.º Z', teacher: $this->colleague());
        $leaked = $this->makeLesson(['class_id' => $foreign->id, 'starts_at' => '2026-10-06 09:30:00']);

        $view = $this->classView('2026-10-05', ['class' => $foreign->ulid]);

        $this->assertSame('5.º A', $view['class']['label']);
        $this->assertNotContains($leaked->ulid, array_column($view['lessons'], 'ulid'));

        $this->assertSame('5.º A', $this->classView('2026-10-05', ['class' => 'nao-existe'])['class']['label']);
        $this->assertSame('7.º A', $this->classView('2026-10-05', ['class' => $this->schoolClass->ulid])['class']['label']);
    }

    #[Test]
    public function the_class_view_class_block_has_the_agreed_shape(): void
    {
        $t1 = $this->makeGroup('T1');

        $view = $this->classView('2026-10-05');

        $this->assertSame(['class', 'group', 'range', 'lessons', 'previous'], array_keys($view));
        $this->assertSame([
            'ulid' => $this->schoolClass->ulid,
            'label' => '7.º A',
            'subject' => $this->schoolClass->subject->name,
            'is_support_class' => false,
            'identity_tone' => 'blue',
            'groups' => [['id' => $t1->id, 'label' => 'T1']],
        ], $view['class']);
    }

    #[Test]
    public function lessons_of_the_teachers_other_classes_never_leak_into_the_class_view(): void
    {
        $mine = $this->makeLesson(['starts_at' => '2026-10-06 09:30:00']);
        $foreign = $this->extraClass('9.º Z', teacher: $this->colleague());
        $this->makeLesson(['class_id' => $foreign->id, 'starts_at' => '2026-10-06 10:30:00']);
        $mySecond = $this->extraClass('8.º B');
        $this->makeLesson(['class_id' => $mySecond->id, 'starts_at' => '2026-10-06 11:30:00']);

        $view = $this->classView('2026-10-05', ['class' => $this->schoolClass->ulid, 'range' => 'ano']);

        $this->assertSame([$mine->ulid], array_column($view['lessons'], 'ulid'));
    }

    #[Test]
    public function an_archived_classs_empty_occurrences_are_rejected_in_the_class_view_too(): void
    {
        $this->schoolClass->update(['archived_at' => '2026-10-01 12:00:00']);
        $this->makeLesson(['starts_at' => '2026-09-24 09:30:00', 'recurring_lesson_slot_id' => $this->makeSlot()->id]);
        $this->makeLesson(['starts_at' => '2026-10-08 09:30:00', 'recurring_lesson_slot_id' => $this->makeSlot(['day_of_week' => 5])->id]);

        $view = $this->classView('2026-10-05', ['range' => 'ano']);

        $this->assertSame(['2026-09-24'], array_map(fn (array $row): string => substr($row['starts_at'], 0, 10), $view['lessons']));
    }

    #[Test]
    public function wide_ranges_never_create_lessons_outside_the_selected_week(): void
    {
        $this->makeSlot(['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);
        $this->inTenant($this->organization, fn () => $this->schoolClass->update(['status' => 'active']));

        $this->asTeacher()->get('/lessons?week=2026-10-05')->assertOk();
        $afterWeek = Lesson::withoutGlobalScopes()->count();
        $this->assertSame(1, $afterWeek);

        $this->asTeacher()->get('/lessons?week=2026-10-05&view=turma&range=4')->assertOk();
        $this->asTeacher()->get('/lessons?week=2026-10-05&view=turma&range=ano')->assertOk();

        $this->assertSame($afterWeek, Lesson::withoutGlobalScopes()->count());
        $this->assertSame(
            ['2026-10-08'],
            Lesson::withoutGlobalScopes()->pluck('starts_at')->map(fn ($startsAt) => substr((string) $startsAt, 0, 10))->all(),
        );
    }

    #[Test]
    public function the_class_view_does_not_materialize_even_the_selected_week_when_the_module_is_read_only(): void
    {
        $this->makeSlot(['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $this->organization->id)
            ->update(['status' => SubscriptionStatus::Suspended]);
        app(Entitlements::class)->flush();

        $this->asTeacher()->get('/lessons?week=2026-10-05&view=turma&range=ano')->assertOk();

        $this->assertSame(0, Lesson::withoutGlobalScopes()->count());
    }

    #[Test]
    public function the_class_view_does_not_touch_summaries_or_versions(): void
    {
        $lesson = $this->makeLesson(['starts_at' => '2026-10-06 09:30:00']);
        $this->summary($lesson, 'Texto.');
        $version = $this->summaryVersion($lesson);

        $this->classView('2026-10-05', ['range' => 'ano']);

        $this->assertSame($version, $this->summaryVersion($lesson));
        $this->assertSame(1, LessonSummary::withoutGlobalScopes()->count());
    }

    #[Test]
    public function invalid_view_or_range_values_are_rejected_but_an_unknown_class_is_not(): void
    {
        $this->asTeacher()->get('/lessons?view=nada')->assertSessionHasErrors('view');
        $this->asTeacher()->get('/lessons?view=turma&range=7')->assertSessionHasErrors('range');
        $this->asTeacher()->get('/lessons?view=turma&class=qualquer-coisa&group=outra')->assertOk();
    }

    #[Test]
    public function a_partial_reload_of_the_class_view_works(): void
    {
        $this->makeLesson(['starts_at' => '2026-10-06 09:30:00']);

        $response = $this->asTeacher()->get('/lessons?week=2026-10-05&view=turma', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $this->asTeacher()->get('/lessons')->viewData('page')['version'],
            'X-Inertia-Partial-Component' => 'lessons/Index',
            'X-Inertia-Partial-Data' => 'classView',
        ])->assertOk();

        $props = $response->json('props');
        $this->assertArrayHasKey('classView', $props);
        $this->assertArrayNotHasKey('lessons', $props);
        $this->assertCount(1, $props['classView']['lessons']);
    }

    // ------------------------------------------------------------------ apoio

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function week(string $week, array $query = []): array
    {
        return $this->props($this->asTeacher()->get('/lessons?'.http_build_query(['week' => $week] + $query)));
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function classView(string $week, array $query = []): array
    {
        /** @var array<string, mixed> $view */
        $view = $this->week($week, ['view' => 'turma'] + $query)['classView'];

        return $view;
    }

    /**
     * @return array<string, mixed>
     */
    private function props(TestResponse $response): array
    {
        $response->assertOk();

        /** @var array<string, mixed> $props */
        $props = $response->viewData('page')['props'];

        return $props;
    }

    private function summary(Lesson $lesson, string $content): void
    {
        $this->inTenant($this->organization, fn () => LessonSummary::create(['lesson_id' => $lesson->id, 'content' => $content]));
    }

    private function colleague(): User
    {
        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);

        return $colleague;
    }

    private function extraClass(string $label, ?User $teacher = null): SchoolClass
    {
        return $this->inTenant($this->organization, function () use ($label, $teacher): SchoolClass {
            $class = SchoolClass::factory()->recycle($this->organization)->create([
                'academic_year_id' => $this->schoolClass->academic_year_id,
                'label' => $label,
            ]);
            $class->teachers()->attach($teacher ?? $this->teacher, ['role' => 'owner']);

            return $class;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function event(SchoolClass $schoolClass, array $attributes = [], ?User $owner = null): CalendarEvent
    {
        return $this->inTenant($this->organization, function () use ($schoolClass, $attributes, $owner): CalendarEvent {
            $event = CalendarEvent::create(array_merge([
                'user_id' => ($owner ?? $this->teacher)->getKey(),
                'type' => CalendarEventType::Meeting,
                'title' => 'Um acontecimento',
                'starts_on' => '2026-10-08',
                'ends_on' => '2026-10-08',
            ], $attributes));
            $event->schoolClasses()->sync([$schoolClass->getKey()]);

            return $event;
        });
    }
}
