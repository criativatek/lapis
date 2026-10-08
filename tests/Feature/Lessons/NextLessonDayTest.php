<?php

namespace Tests\Feature\Lessons;

use App\Models\AcademicCalendarException;
use App\Models\AcademicCalendarExceptionType;
use App\Models\CancelledLessonOccurrence;
use App\Models\Lesson;
use App\Models\LessonOrigin;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * «Aulas de hoje» → `GET lessons/next-day`: o próximo dia com aula do
 * professor, SÓ LEITURA. Olha para as aulas que já existem e para as
 * ocorrências do horário ainda por materializar, e nunca cria nenhuma.
 *
 * Relógio congelado numa quinta-feira (08/10/2026, 11:00 em Lisboa). O ano
 * letivo das fixtures vai de 2026-09-01 a 2027-06-30.
 */
class NextLessonDayTest extends TestCase
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
    public function the_next_lesson_still_this_week_is_returned_and_never_a_day_before_today(): void
    {
        $this->makeLesson(['starts_at' => '2026-10-07 09:30:00']);
        $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);
        $this->makeLesson(['starts_at' => '2026-10-09 09:30:00']);

        // Estritamente depois de quarta-feira, mas hoje (quinta) conta.
        $this->assertSame('2026-10-08', $this->nextDay('2026-10-07'));
        // Depois de hoje: o dia seguinte.
        $this->assertSame('2026-10-09', $this->nextDay('2026-10-08'));
        // Um `after` antigo nunca devolve um dia que já passou.
        $this->assertSame('2026-10-08', $this->nextDay('2026-09-01'));
        $this->assertNull($this->nextDay('2026-10-09'));
    }

    #[Test]
    public function a_lesson_that_only_exists_in_the_timetable_next_week_is_found_without_creating_anything(): void
    {
        // Terças-feiras. Nenhuma aula materializada: a semana seguinte nunca foi aberta.
        $this->makeSlot(['day_of_week' => 2]);
        $before = Lesson::withoutGlobalScopes()->count();

        $this->assertSame('2026-10-13', $this->nextDay('2026-10-11'));
        $this->assertSame('2026-10-13', $this->nextDay('2026-10-08'));
        $this->assertSame($before, Lesson::withoutGlobalScopes()->count());
    }

    #[Test]
    public function the_earlier_of_an_existing_lesson_and_a_timetable_occurrence_wins(): void
    {
        $this->makeSlot(['day_of_week' => 2]);
        $this->makeLesson(['starts_at' => '2026-10-14 09:30:00', 'origin' => LessonOrigin::Manual]);

        $this->assertSame('2026-10-13', $this->nextDay('2026-10-08'));
        $this->assertSame('2026-10-14', $this->nextDay('2026-10-13'));
    }

    #[Test]
    public function holidays_and_cancelled_occurrences_are_skipped(): void
    {
        $slot = $this->makeSlot(['day_of_week' => 2]);
        $this->inTenant($this->organization, function () use ($slot): void {
            AcademicCalendarException::factory()
                ->recycle($this->organization)
                ->for($this->schoolClass->academicYear)
                ->create([
                    'type' => AcademicCalendarExceptionType::Holiday,
                    'title' => 'Feriado',
                    'starts_on' => '2026-10-13',
                    'ends_on' => '2026-10-13',
                ]);
            CancelledLessonOccurrence::create([
                'class_id' => $this->schoolClass->id,
                'class_group_id' => null,
                'recurring_lesson_slot_id' => $slot->id,
                'occurs_at' => '2026-10-20 09:30:00',
                'cancelled_by' => $this->teacher->id,
            ]);
        });

        $this->assertSame('2026-10-27', $this->nextDay('2026-10-08'));
    }

    #[Test]
    public function the_classes_filter_is_respected_and_other_teachers_classes_are_ignored(): void
    {
        $this->makeSlot(['day_of_week' => 2]);
        $second = $this->secondClass($this->teacher);
        $this->makeSlot(['class_id' => $second->id, 'day_of_week' => 3]);

        $stranger = User::factory()->create();
        $this->organization->members()->attach($stranger, ['joined_at' => now()]);
        $foreign = $this->secondClass($stranger);
        $this->makeSlot(['class_id' => $foreign->id, 'day_of_week' => 1]);

        // Sem filtro: a mais próxima das turmas DELE (terça), não a segunda-feira do colega.
        $this->assertSame('2026-10-13', $this->nextDay('2026-10-08'));
        $this->assertSame('2026-10-13', $this->nextDay('2026-10-08', [$this->schoolClass->ulid]));
        $this->assertSame('2026-10-14', $this->nextDay('2026-10-08', [$second->ulid]));
        $this->assertSame('2026-10-13', $this->nextDay('2026-10-08', [$this->schoolClass->ulid, $second->ulid]));
        // Um ULID desconhecido é ignorado; o outro filtro continua a valer.
        $this->assertSame('2026-10-14', $this->nextDay('2026-10-08', ['nao-existe', $second->ulid]));
        // Só ULIDs que não são dele: nada — e nunca «todas as turmas».
        $this->assertNull($this->nextDay('2026-10-08', [$foreign->ulid]));
        $this->assertNull($this->nextDay('2026-10-08', ['nao-existe']));
    }

    #[Test]
    public function it_answers_null_when_there_are_no_more_lessons_in_the_academic_year(): void
    {
        $this->makeSlot(['day_of_week' => 2, 'starts_on' => '2026-09-01', 'ends_on' => '2026-10-10']);
        $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);

        $this->assertNull($this->nextDay('2026-10-08'));
        // Depois do fim do ano letivo.
        $this->assertNull($this->nextDay('2027-06-30'));
    }

    #[Test]
    public function an_archived_class_contributes_nothing_after_its_archival_day(): void
    {
        // Arquivada na segunda-feira 12/10: o último dia com horário foi domingo 11/10.
        $this->makeSlot(['day_of_week' => 2]);
        $this->inTenant($this->organization, fn (): int => SchoolClass::query()
            ->whereKey($this->schoolClass->getKey())
            ->update(['archived_at' => '2026-10-12 10:00:00']));

        // Nem o horário por materializar...
        $this->assertNull($this->nextDay('2026-10-08'));

        // ...nem uma ocorrência VAZIA já materializada (a semana também a esconde).
        $this->makeLesson(['starts_at' => '2026-10-13 09:30:00']);
        $this->assertNull($this->nextDay('2026-10-08'));

        // Mas uma aula introduzida à mão continua a ser trabalho e conta.
        $this->makeLesson(['starts_at' => '2026-10-15 09:30:00', 'origin' => LessonOrigin::Manual]);
        $this->assertSame('2026-10-15', $this->nextDay('2026-10-08'));
    }

    #[Test]
    public function today_is_the_lisbon_day_even_when_utc_is_still_on_the_previous_one(): void
    {
        // 23:30 UTC de quinta-feira são 00:30 de sexta-feira em Lisboa (WEST).
        Carbon::setTestNow(Carbon::parse('2026-10-08 23:30:00', 'UTC'));
        $this->makeLesson(['starts_at' => '2026-10-08 09:30:00']);
        $this->makeLesson(['starts_at' => '2026-10-09 10:30:00']);
        $this->makeLesson(['starts_at' => '2026-10-12 09:30:00']);

        // Hoje, em Lisboa, já é sexta: a quinta não pode ser devolvida.
        $this->assertSame('2026-10-09', $this->nextDay('2026-10-01'));

        $this->asTeacher()
            ->withSession(['academic_year_id' => $this->schoolClass->academic_year_id])
            ->get('/lessons?week=2026-10-05')
            ->assertInertia(fn ($page) => $page->where('today', '2026-10-09'));
    }

    #[Test]
    public function the_request_is_validated(): void
    {
        $this->asTeacher()
            ->withSession(['academic_year_id' => $this->schoolClass->academic_year_id])
            ->getJson('/lessons/next-day')
            ->assertStatus(422)
            ->assertJsonValidationErrors('after');

        $this->asTeacher()
            ->withSession(['academic_year_id' => $this->schoolClass->academic_year_id])
            ->getJson('/lessons/next-day?after=08-10-2026')
            ->assertStatus(422);
    }

    #[Test]
    public function a_guest_gets_nothing(): void
    {
        $this->getJson('/lessons/next-day?after=2026-10-08')->assertUnauthorized();
    }

    /**
     * @param  list<string>  $classes
     */
    private function nextDay(string $after, array $classes = []): ?string
    {
        $query = http_build_query(array_filter([
            'after' => $after,
            'classes' => $classes === [] ? null : implode(',', $classes),
        ]));

        $response = $this->asTeacher()
            ->withSession(['academic_year_id' => $this->schoolClass->academic_year_id])
            ->getJson('/lessons/next-day?'.$query)
            ->assertOk();

        return $response->json('date');
    }

    private function secondClass(User $teacher): SchoolClass
    {
        return $this->inTenant($this->organization, function () use ($teacher): SchoolClass {
            $class = SchoolClass::factory()->recycle($this->organization)->create([
                'academic_year_id' => $this->schoolClass->academic_year_id,
            ]);
            $class->teachers()->attach($teacher, ['role' => 'owner']);

            return $class;
        });
    }
}
