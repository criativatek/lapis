<?php

namespace Tests\Feature\Lessons;

use App\Models\AcademicYear;
use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
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
 * `GET lessons/search`: a pesquisa nos sumários de todo o ano letivo. Só
 * leitura, só `content`, só as aulas das turmas deste professor.
 *
 * Relógio congelado em 08/10/2026. O ano letivo das fixtures vai de
 * 2026-09-01 a 2027-06-30.
 */
class LessonSummarySearchTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-08 11:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function a_word_is_found_whatever_the_capitals_and_accents(): void
    {
        $lesson = $this->taughtLesson('2026-10-08 09:30:00', 'Revisão de Frações equivalentes.');
        $this->taughtLesson('2026-10-07 09:30:00', 'Geometria: triângulos.');

        $this->search(['q' => 'FRACOES'])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('occurrences', 1)
            ->assertJsonPath('results.0.ulid', $lesson->ulid);

        $this->search(['q' => 'fraçÕes'])->assertJsonPath('total', 1);
    }

    #[Test]
    public function a_phrase_is_found_in_sequence_even_across_a_line_break_and_not_in_another_order(): void
    {
        $this->taughtLesson('2026-10-08 09:30:00', "Frações\nequivalentes e simplificação.");
        $this->taughtLesson('2026-10-07 09:30:00', 'Equivalentes: frações irredutíveis.');

        $this->search(['q' => 'frações equivalentes'])
            ->assertJsonPath('total', 1);
        $this->search(['q' => '"frações equivalentes"'])
            ->assertJsonPath('total', 1);
        $this->search(['q' => 'equivalentes frações'])
            ->assertJsonPath('total', 0);
    }

    #[Test]
    public function text_that_only_appears_at_the_end_of_a_long_summary_is_found_and_shown(): void
    {
        $paragraphs = implode("\n\n", array_map(fn (int $i): string => "Parágrafo {$i} sobre outro assunto qualquer, bem comprido para encher.", range(1, 12)));
        $this->taughtLesson('2026-10-08 09:30:00', $paragraphs."\n\nNo fim: proporcionalidade inversa.");

        $response = $this->search(['q' => 'proporcionalidade inversa'])->assertJsonPath('total', 1);

        $segments = $response->json('results.0.segments');
        $this->assertSame('proporcionalidade inversa', collect($segments)->firstWhere('match', true)['text']);
        $this->assertStringContainsString('Parágrafo 12', collect($segments)->pluck('text')->implode(''));
    }

    #[Test]
    public function lessons_from_different_weeks_and_months_are_found_but_not_those_of_another_academic_year(): void
    {
        $this->taughtLesson('2026-09-15 09:30:00', 'Potências de base 10.');
        $this->taughtLesson('2026-11-20 09:30:00', 'Potências de base 2.');
        $this->taughtLesson('2027-03-05 09:30:00', 'Potências e raízes.');

        $previousYearClass = $this->inTenant($this->organization, function (): SchoolClass {
            $class = SchoolClass::factory()->recycle($this->organization)->create([
                'academic_year_id' => AcademicYear::factory()->recycle($this->organization)
                    ->create(['starts_on' => '2025-09-01', 'ends_on' => '2026-06-30'])->id,
            ]);
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);

            return $class;
        });
        $this->taughtLesson('2026-03-05 09:30:00', 'Potências do ano passado.', ['class_id' => $previousYearClass->id]);

        $this->search(['q' => 'potências'])
            ->assertJsonPath('total', 3)
            ->assertJsonPath('results.0.starts_at', Carbon::parse('2027-03-05 09:30:00', 'Europe/Lisbon')->toIso8601String());
    }

    #[Test]
    public function the_class_filter_is_an_intersection_and_never_falls_back_to_all_classes(): void
    {
        $other = $this->secondClass();
        $mine = $this->taughtLesson('2026-10-08 09:30:00', 'Equações do 1.º grau.');
        $theirs = $this->taughtLesson('2026-10-07 09:30:00', 'Equações do 2.º grau.', ['class_id' => $other->id]);
        $foreign = $this->foreignClass();

        $this->search(['q' => 'equações', 'classes' => $this->schoolClass->ulid])
            ->assertJsonPath('total', 1)
            ->assertJsonPath('results.0.ulid', $mine->ulid);

        $this->search(['q' => 'equações', 'classes' => $this->schoolClass->ulid.','.$other->ulid])
            ->assertJsonPath('total', 2);

        // Um ULID desconhecido é ignorado quando há outro válido.
        $this->search(['q' => 'equações', 'classes' => 'desconhecido,'.$other->ulid])
            ->assertJsonPath('total', 1)
            ->assertJsonPath('results.0.ulid', $theirs->ulid);

        // Só ULIDs alheios ou desconhecidos: zero, nunca todas.
        $this->search(['q' => 'equações', 'classes' => $foreign->ulid.',desconhecido'])
            ->assertJsonPath('total', 0)
            ->assertJsonPath('results', []);
    }

    #[Test]
    public function the_date_filters_are_inclusive_local_days(): void
    {
        $this->taughtLesson('2026-10-06 23:59:00', 'Estatística: média.');
        $this->taughtLesson('2026-10-07 00:00:00', 'Estatística: moda.');
        $this->taughtLesson('2026-10-08 23:59:00', 'Estatística: mediana.');
        $this->taughtLesson('2026-10-09 00:00:00', 'Estatística: amplitude.');

        $this->search(['q' => 'estatística', 'from' => '2026-10-07'])->assertJsonPath('total', 3);
        $this->search(['q' => 'estatística', 'to' => '2026-10-08'])->assertJsonPath('total', 3);
        $this->search(['q' => 'estatística', 'from' => '2026-10-07', 'to' => '2026-10-08'])->assertJsonPath('total', 2);
        $this->search(['q' => 'estatística', 'from' => '2026-10-08', 'to' => '2026-10-08'])->assertJsonPath('total', 1);
    }

    #[Test]
    public function the_state_filters_combine_and_default_to_taught_only(): void
    {
        $taught = $this->taughtLesson('2026-10-01 09:30:00', 'Área do círculo.');
        $legacy = $this->makeLesson(['starts_at' => '2026-10-02 09:30:00', 'status' => LessonStatus::Taught]);
        $this->summarize($legacy, 'Área do círculo.');
        $prepared = $this->makeLesson(['starts_at' => '2026-10-03 09:30:00', 'status' => LessonStatus::Prepared]);
        $this->summarize($prepared, 'Área do círculo.');
        $planning = $this->makeLesson(['starts_at' => '2026-10-04 09:30:00', 'status' => LessonStatus::Preparation]);
        $this->summarize($planning, 'Área do círculo.');
        $absent = $this->makeLesson(['starts_at' => '2026-10-05 09:30:00', 'outcome' => LessonOutcome::TeacherAbsent]);
        $this->summarize($absent, 'Área do círculo.');
        $activity = $this->makeLesson(['starts_at' => '2026-10-06 09:30:00', 'outcome' => LessonOutcome::ClassExternalActivity]);
        $this->summarize($activity, 'Área do círculo.');

        $ulids = fn (TestResponse $response): array => collect($response->json('results'))->pluck('ulid')->sort()->values()->all();
        $sorted = fn (Lesson ...$lessons): array => collect($lessons)->pluck('ulid')->sort()->values()->all();

        // Por omissão, só lecionadas — inclui a do estado antigo, sem `outcome`.
        $this->assertSame($sorted($taught, $legacy), $ulids($this->search(['q' => 'círculo'])));
        $this->assertSame($sorted($prepared, $planning), $ulids($this->search(['q' => 'círculo', 'states' => 'prepared'])));
        $this->assertSame($sorted($taught, $legacy, $prepared, $planning), $ulids($this->search(['q' => 'círculo', 'states' => 'taught,prepared'])));
        $this->assertSame($sorted($absent, $activity), $ulids($this->search(['q' => 'círculo', 'states' => 'other'])));
        $this->search(['q' => 'círculo', 'states' => 'taught,prepared,other'])->assertJsonPath('total', 6);
    }

    #[Test]
    public function taught_and_prepared_lessons_are_told_apart_in_the_payload(): void
    {
        $this->taughtLesson('2026-10-02 09:30:00', 'Perímetros.');
        $prepared = $this->makeLesson(['starts_at' => '2026-10-03 09:30:00', 'status' => LessonStatus::Prepared]);
        $this->summarize($prepared, 'Perímetros.');

        $results = $this->search(['q' => 'perímetros', 'states' => 'taught,prepared'])->json('results');

        $this->assertSame('prepared', $results[0]['status']);
        $this->assertNull($results[0]['outcome']);
        $this->assertSame('Preparada', $results[0]['status_label']);
        $this->assertSame('taught', $results[1]['outcome']);
        $this->assertSame('Lecionada', $results[1]['outcome_label']);
    }

    #[Test]
    public function two_groups_with_the_same_lesson_number_and_text_are_two_results(): void
    {
        $groupOne = $this->makeGroup('T1');
        $groupTwo = $this->makeGroup('T2');
        $this->taughtLesson('2026-10-08 09:30:00', 'Funções afins.', ['class_group_id' => $groupOne->id, 'lesson_number' => 7]);
        $this->taughtLesson('2026-10-08 09:30:00', 'Funções afins.', ['class_group_id' => $groupTwo->id, 'lesson_number' => 7]);

        $response = $this->search(['q' => 'funções afins'])->assertJsonPath('total', 2);

        $this->assertEqualsCanonicalizing(
            ['8.º F · T1', '8.º F · T2'],
            array_map(
                fn (string $label): string => str_replace($this->schoolClass->label, '8.º F', $label),
                array_column($response->json('results'), 'context_label'),
            ),
        );
        $this->assertSame([7, 7], array_column($response->json('results'), 'lesson_number'));
    }

    #[Test]
    public function other_teachers_and_other_organizations_never_appear_nor_count(): void
    {
        $mine = $this->taughtLesson('2026-10-08 09:30:00', 'Trigonometria básica.');

        // Outro professor, MESMA organização, turma dele.
        $colleague = User::factory()->create();
        $colleagueClass = $this->inTenant($this->organization, function () use ($colleague): SchoolClass {
            $class = SchoolClass::factory()->recycle($this->organization)->create(['academic_year_id' => $this->schoolClass->academic_year_id]);
            $class->teachers()->attach($colleague, ['role' => 'owner']);

            return $class;
        });
        $this->taughtLesson('2026-10-07 09:30:00', 'Trigonometria básica do colega.', ['class_id' => $colleagueClass->id]);

        // Outra organização, o mesmo texto.
        $this->foreignLesson('Trigonometria básica noutra escola.');

        $this->search(['q' => 'trigonometria'])
            ->assertJsonPath('total', 1)
            ->assertJsonPath('occurrences', 1)
            ->assertJsonPath('results.0.ulid', $mine->ulid);
    }

    #[Test]
    public function a_lesson_row_of_another_organization_never_appears_even_pointing_at_my_class(): void
    {
        // Defesa em profundidade: uma linha inconsistente (de outra
        // organização, mas a apontar para a MINHA turma) só fica de fora pelo
        // escopo de organização — que tem de valer também com o `join` aos
        // sumários e o `toBase()` da consulta.
        $mine = $this->taughtLesson('2026-10-08 09:30:00', 'Trigonometria básica.');
        $otherOrganization = User::factory()->create()->personalOrganization();
        $this->inTenant($otherOrganization, function () use ($otherOrganization): void {
            $stray = Lesson::withoutGlobalScopes()->forceCreate([
                'organization_id' => $otherOrganization->id,
                'class_id' => $this->schoolClass->id,
                'starts_at' => '2026-10-09 09:30:00',
                'ends_at' => '2026-10-09 10:20:00',
                'status' => LessonStatus::Taught->value,
                'outcome' => LessonOutcome::Taught->value,
                'created_by' => $this->teacher->id,
            ]);
            LessonSummary::withoutGlobalScopes()->forceCreate([
                'organization_id' => $otherOrganization->id,
                'lesson_id' => $stray->id,
                'content' => 'Trigonometria básica de outra organização.',
            ]);
        });
        $this->assertSame(2, Lesson::withoutGlobalScopes()->where('class_id', $this->schoolClass->id)->count());

        $this->search(['q' => 'trigonometria'])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('occurrences', 1)
            ->assertJsonPath('results.0.ulid', $mine->ulid);
    }

    #[Test]
    public function the_response_says_the_academic_year_and_the_classes_actually_searched(): void
    {
        $second = $this->secondClass();
        $this->taughtLesson('2026-10-08 09:30:00', 'Sistemas de equações.');

        $this->search(['q' => 'equações'])
            ->assertJsonPath('academic_year', $this->schoolClass->academicYear->label)
            ->assertJsonPath('classes', []);

        // As turmas desconhecidas saem da lista aplicada; as do professor ficam.
        $this->search(['q' => 'equações', 'classes' => 'nao-existe,'.$second->ulid])
            ->assertJsonPath('classes', [$second->ulid])
            ->assertJsonPath('total', 0);
    }

    #[Test]
    public function an_archived_class_is_still_searched(): void
    {
        $this->inTenant($this->organization, fn () => $this->schoolClass->forceFill(['archived_at' => '2026-10-01 18:00:00'])->save());
        $lesson = $this->taughtLesson('2026-09-24 09:30:00', 'Proporcionalidade direta.');

        $this->search(['q' => 'proporcionalidade'])
            ->assertJsonPath('total', 1)
            ->assertJsonPath('results.0.ulid', $lesson->ulid);
    }

    #[Test]
    public function a_support_session_and_a_read_only_module_can_still_search(): void
    {
        $lesson = $this->taughtLesson('2026-10-08 09:30:00', 'Teorema de Pitágoras.');

        // Impersonação: é uma leitura, como a semana.
        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id, 'impersonator_id' => 999])
            ->getJson('/lessons/search?q=pitagoras')
            ->assertOk()
            ->assertJsonPath('results.0.ulid', $lesson->ulid);

        // Módulo só de leitura (assinatura suspensa): continua a ler.
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $this->organization->id)
            ->update(['status' => SubscriptionStatus::Suspended]);
        app(Entitlements::class)->flush();

        $this->search(['q' => 'pitagoras'])
            ->assertOk()
            ->assertJsonPath('results.0.ulid', $lesson->ulid);
    }

    #[Test]
    public function only_the_summary_content_is_searched(): void
    {
        $lesson = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00', 'outcome' => LessonOutcome::Taught]);
        $this->summarize($lesson, 'Sumário sem o termo.', [
            'private_notes' => 'A palavra secreta está nas notas.',
            'resources' => 'A palavra secreta está nos recursos.',
            'homework' => 'A palavra secreta está nos trabalhos de casa.',
        ]);

        $this->search(['q' => 'palavra secreta'])->assertJsonPath('total', 0);

        $other = $this->taughtLesson('2026-10-07 09:30:00', 'A palavra secreta está no sumário.');
        $this->search(['q' => 'palavra secreta'])
            ->assertJsonPath('total', 1)
            ->assertJsonPath('results.0.ulid', $other->ulid);
    }

    #[Test]
    public function totals_count_lessons_and_occurrences_across_all_pages_and_pages_are_clamped(): void
    {
        foreach (range(1, 25) as $day) {
            $this->taughtLesson(
                Carbon::parse('2026-09-01 09:30:00')->addDays($day)->toDateTimeString(),
                $day === 3 ? 'Limites: limites laterais e limites no infinito.' : "Limites {$day}.",
            );
        }

        $first = $this->search(['q' => 'limites'])
            ->assertJsonPath('total', 25)
            ->assertJsonPath('occurrences', 27)
            ->assertJsonPath('page', 1)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('per_page', 20);
        $this->assertCount(20, $first->json('results'));
        // Mais recentes primeiro.
        $this->assertSame(
            Carbon::parse('2026-09-26 09:30:00', 'Europe/Lisbon')->toIso8601String(),
            $first->json('results.0.starts_at'),
        );

        $second = $this->search(['q' => 'limites', 'page' => 2])->assertJsonPath('page', 2);
        $this->assertCount(5, $second->json('results'));
        $this->assertSame(27, $second->json('occurrences'));
        $this->assertSame(3, collect($second->json('results'))->firstWhere('occurrences', 3)['occurrences']);

        $this->search(['q' => 'limites', 'page' => 9])
            ->assertJsonPath('page', 2)
            ->assertJsonCount(5, 'results');
    }

    #[Test]
    public function the_segments_rebuild_the_whole_summary_and_html_is_plain_text(): void
    {
        $content = "Usar <b>frações</b> e FRAÇÕES.\nO 2.º exercício: frações.";
        $this->taughtLesson('2026-10-08 09:30:00', $content);

        $segments = $this->search(['q' => 'fracoes'])->assertJsonPath('occurrences', 3)->json('results.0.segments');

        $this->assertSame($content, implode('', array_column($segments, 'text')));
        $this->assertSame(['frações', 'FRAÇÕES', 'frações'], array_column(array_filter($segments, fn (array $segment): bool => $segment['match']), 'text'));
        $this->assertStringContainsString('<b>', $segments[0]['text']);
    }

    #[Test]
    public function an_invalid_request_is_refused_with_a_validation_error(): void
    {
        $this->search(['q' => 'a'])->assertStatus(422)->assertJsonValidationErrors('q');
        $this->search(['q' => '   '])->assertStatus(422)->assertJsonValidationErrors('q');
        $this->search(['q' => '" "'])->assertStatus(422)->assertJsonValidationErrors('q');
        $this->search(['q' => '"a"'])->assertStatus(422)->assertJsonValidationErrors('q');
        $this->search([])->assertStatus(422)->assertJsonValidationErrors('q');
        $this->search(['q' => 'ab', 'from' => '2026-10-08', 'to' => '2026-10-07'])->assertStatus(422)->assertJsonValidationErrors('to');
        $this->search(['q' => 'ab', 'states' => 'xyz'])->assertStatus(422)->assertJsonValidationErrors('states');
        $this->search(['q' => 'ab', 'states' => 'taught,xyz'])->assertStatus(422)->assertJsonValidationErrors('states');
        $this->search(['q' => 'ab', 'from' => '08/10/2026'])->assertStatus(422)->assertJsonValidationErrors('from');
        $this->search(['q' => str_repeat('a', 201)])->assertStatus(422)->assertJsonValidationErrors('q');
    }

    #[Test]
    public function the_route_needs_a_login_and_the_lessons_module(): void
    {
        $this->getJson('/lessons/search?q=ab')->assertUnauthorized();

        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $this->organization->id)
            ->delete();
        app(Entitlements::class)->flush();

        $this->search(['q' => 'ab'])->assertForbidden();
    }

    #[Test]
    public function searching_never_creates_or_changes_a_lesson(): void
    {
        $this->makeSlot(['day_of_week' => 2]);
        $lesson = $this->taughtLesson('2026-10-08 09:30:00', 'Sólidos geométricos.');
        $before = Lesson::withoutGlobalScopes()->count();
        $updatedAt = Lesson::withoutGlobalScopes()->whereKey($lesson->getKey())->value('updated_at');
        $version = $this->summaryVersion($lesson);

        $this->search(['q' => 'sólidos'])->assertJsonPath('total', 1);

        $this->assertSame($before, Lesson::withoutGlobalScopes()->count());
        $this->assertEquals($updatedAt, Lesson::withoutGlobalScopes()->whereKey($lesson->getKey())->value('updated_at'));
        $this->assertSame($version, $this->summaryVersion($lesson));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function search(array $query): TestResponse
    {
        return $this->asTeacher()->getJson('/lessons/search?'.http_build_query($query));
    }

    /**
     * Uma aula lecionada (`outcome = taught`) com o sumário dado.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function taughtLesson(string $startsAt, string $content, array $attributes = []): Lesson
    {
        $lesson = $this->makeLesson(array_merge([
            'starts_at' => $startsAt,
            'status' => LessonStatus::Taught,
            'outcome' => LessonOutcome::Taught,
        ], $attributes));
        $this->summarize($lesson, $content);

        return $lesson;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function summarize(Lesson $lesson, string $content, array $extra = []): LessonSummary
    {
        return $this->inTenant($this->organization, fn (): LessonSummary => LessonSummary::create(array_merge([
            'lesson_id' => $lesson->id,
            'content' => $content,
        ], $extra)));
    }

    /** Outra turma do MESMO professor, no mesmo ano letivo. */
    private function secondClass(): SchoolClass
    {
        return $this->inTenant($this->organization, function (): SchoolClass {
            $class = SchoolClass::factory()->recycle($this->organization)->create(['academic_year_id' => $this->schoolClass->academic_year_id]);
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);

            return $class;
        });
    }

    /** Uma turma que este professor NÃO leciona (de outro colega, na mesma organização). */
    private function foreignClass(): SchoolClass
    {
        $colleague = User::factory()->create();

        return $this->inTenant($this->organization, function () use ($colleague): SchoolClass {
            $class = SchoolClass::factory()->recycle($this->organization)->create(['academic_year_id' => $this->schoolClass->academic_year_id]);
            $class->teachers()->attach($colleague, ['role' => 'owner']);

            return $class;
        });
    }

    private function foreignLesson(string $content): void
    {
        $otherTeacher = User::factory()->create();
        $otherOrganization = $otherTeacher->personalOrganization();
        $this->subscribeToPro($otherOrganization);

        $this->inTenant($otherOrganization, function () use ($otherTeacher, $otherOrganization, $content): void {
            $class = SchoolClass::factory()->recycle($otherOrganization)->create([
                'academic_year_id' => AcademicYear::factory()->recycle($otherOrganization)
                    ->create(['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30'])->id,
            ]);
            $class->teachers()->attach($otherTeacher, ['role' => 'owner']);
            $lesson = Lesson::create([
                'class_id' => $class->id,
                'starts_at' => '2026-10-08 09:30:00',
                'ends_at' => '2026-10-08 10:20:00',
                'status' => LessonStatus::Taught,
                'outcome' => LessonOutcome::Taught,
                'created_by' => $otherTeacher->id,
            ]);
            LessonSummary::create(['lesson_id' => $lesson->id, 'content' => $content]);
        });
    }
}
