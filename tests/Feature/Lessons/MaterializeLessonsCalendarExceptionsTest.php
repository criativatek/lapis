<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\MaterializeLessonsForRange;
use App\Actions\Lessons\MaterializeLessonsForWeek;
use App\Models\AcademicCalendarException;
use App\Models\AcademicCalendarExceptionType;
use App\Models\AcademicYear;
use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Num feriado não há aula» (Fase 5.5) — a única consequência que uma exceção
 * letiva tem sobre as aulas.
 *
 * A GUARDA VIVE NA MATERIALIZAÇÃO, e é por isso que é aqui que se prova: em
 * MaterializeLessonsForRange, o sítio por onde TODOS os caminhos passam, e não
 * na página que por acaso hoje o chama. O último teste materializa pela semana
 * (MaterializeLessonsForWeek, o que o LessonWeekController usa de facto) para o
 * mostrar de ponta a ponta em vez de o presumir.
 *
 * O QUE ELA NÃO FAZ É METADE DO ASSUNTO. Não apaga aulas, não altera aulas, não
 * toca no RecurringLessonSlot: impede a criação de uma aula NOVA numa data
 * excecionada e mais nada. A rotina sobrevive ao feriado, e a aula que já
 * existia sobrevive à exceção que apareceu depois dela.
 *
 * A semana de referência é a de 2026-09-07 (segunda-feira) a 2026-09-13.
 */
class MaterializeLessonsCalendarExceptionsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_ordinary_weekday_without_any_exception_still_materializes_its_lesson(): void
    {
        [$teacher, $organization, , $schoolClass] = $this->context();
        $this->slot($organization, $schoolClass, 1);

        $lessons = $this->materialize($organization, $schoolClass, '2026-09-07', '2026-09-13', $teacher);

        $this->assertCount(1, $lessons);
        $this->assertSame(['2026-09-07 09:30:00'], $this->lessonTimes($organization));
    }

    #[Test]
    public function a_holiday_stops_the_lesson_of_that_day_from_being_created(): void
    {
        [$teacher, $organization, $year, $schoolClass] = $this->context();
        $this->slot($organization, $schoolClass, 1);
        $this->exception($organization, $year, [
            'type' => AcademicCalendarExceptionType::Holiday,
            'title' => 'Feriado municipal',
            'starts_on' => '2026-09-07',
            'ends_on' => '2026-09-07',
        ]);

        $lessons = $this->materialize($organization, $schoolClass, '2026-09-07', '2026-09-13', $teacher);

        $this->assertCount(0, $lessons);
        $this->assertDatabaseCount('lessons', 0);
    }

    /**
     * Os limites são INCLUSIVOS dos dois lados: o primeiro e o último dia da
     * interrupção são dela. O dia imediatamente antes e o imediatamente depois
     * não são, e nesses a aula nasce como sempre nasceu — é isso que distingue
     * uma guarda de um apagão do intervalo inteiro.
     */
    #[Test]
    public function a_school_break_stops_every_day_inside_it_and_neither_day_around_it(): void
    {
        [$teacher, $organization, $year, $schoolClass] = $this->context();
        foreach ([1 => '09:30', 2 => '10:30', 3 => '11:30', 4 => '12:30', 5 => '14:30'] as $weekday => $startsAt) {
            $this->slot($organization, $schoolClass, $weekday, $startsAt);
        }
        $this->exception($organization, $year, [
            'type' => AcademicCalendarExceptionType::SchoolBreak,
            'title' => 'Interrupção letiva',
            'starts_on' => '2026-09-08',
            'ends_on' => '2026-09-10',
        ]);

        $this->materialize($organization, $schoolClass, '2026-09-07', '2026-09-13', $teacher);

        // Segunda (véspera) e sexta (dia seguinte) sim; terça, quarta e quinta
        // — os dois limites e o meio da interrupção — não.
        $this->assertSame(
            ['2026-09-07 09:30:00', '2026-09-11 14:30:00'],
            $this->lessonTimes($organization),
        );
    }

    #[Test]
    public function a_non_teaching_day_stops_the_lesson_exactly_like_a_holiday_does(): void
    {
        [$teacher, $organization, $year, $schoolClass] = $this->context();
        $this->slot($organization, $schoolClass, 1);
        $this->exception($organization, $year, [
            'type' => AcademicCalendarExceptionType::NonTeachingDay,
            'title' => 'Dia não letivo',
            'starts_on' => '2026-09-07',
            'ends_on' => '2026-09-07',
        ]);

        $this->materialize($organization, $schoolClass, '2026-09-07', '2026-09-13', $teacher);

        $this->assertDatabaseCount('lessons', 0);
    }

    /**
     * O feriado não desfaz a rotina. «Português às segundas» continua a existir,
     * intacto até ao `updated_at`, e a segunda-feira seguinte continua a receber
     * a sua aula — o que não aconteceu foi UMA ocorrência, e não o horário.
     */
    #[Test]
    public function the_recurring_slot_is_left_completely_untouched_by_the_guard(): void
    {
        [$teacher, $organization, $year, $schoolClass] = $this->context();
        $slot = $this->slot($organization, $schoolClass, 1);
        $before = $this->byColumn($slot->getAttributes());
        $this->exception($organization, $year, [
            'title' => 'Feriado municipal',
            'starts_on' => '2026-09-07',
            'ends_on' => '2026-09-07',
        ]);

        $this->materialize($organization, $schoolClass, '2026-09-07', '2026-09-20', $teacher);

        $this->assertDatabaseCount('recurring_lesson_slots', 1);
        $this->assertSame($before, $this->inTenant(
            $organization,
            fn (): array => $this->byColumn(RecurringLessonSlot::query()->sole()->getAttributes()),
        ));
        // A segunda-feira seguinte, essa, tem aula.
        $this->assertSame(['2026-09-14 09:30:00'], $this->lessonTimes($organization));
    }

    #[Test]
    public function materializing_the_same_range_twice_over_an_exception_stays_idempotent(): void
    {
        [$teacher, $organization, $year, $schoolClass] = $this->context();
        foreach ([1 => '09:30', 3 => '11:30', 5 => '14:30'] as $weekday => $startsAt) {
            $this->slot($organization, $schoolClass, $weekday, $startsAt);
        }
        $this->exception($organization, $year, [
            'type' => AcademicCalendarExceptionType::SchoolBreak,
            'title' => 'Interrupção letiva',
            'starts_on' => '2026-09-08',
            'ends_on' => '2026-09-10',
        ]);

        $this->materialize($organization, $schoolClass, '2026-09-07', '2026-09-13', $teacher);
        $first = $this->lessonTimes($organization);
        $this->materialize($organization, $schoolClass, '2026-09-07', '2026-09-13', $teacher);

        $this->assertSame(['2026-09-07 09:30:00', '2026-09-11 14:30:00'], $first);
        $this->assertSame($first, $this->lessonTimes($organization));
        $this->assertDatabaseCount('lessons', 2);
    }

    /**
     * O global scope do próprio modelo é a fronteira — não há filtro manual de
     * organização por cima dele —, e é essa fronteira que este teste prova: o
     * feriado de outra escola, na mesma data, não tem efeito nenhum aqui.
     */
    #[Test]
    public function an_exception_of_another_organization_never_blocks_the_lesson(): void
    {
        [$teacher, $organization, , $schoolClass] = $this->context();
        $this->slot($organization, $schoolClass, 1);
        [, $foreignOrganization, $foreignYear] = $this->context();
        $this->exception($foreignOrganization, $foreignYear, [
            'title' => 'Feriado de outra escola',
            'starts_on' => '2026-09-07',
            'ends_on' => '2026-09-07',
        ]);

        $this->materialize($organization, $schoolClass, '2026-09-07', '2026-09-13', $teacher);

        $this->assertSame(['2026-09-07 09:30:00'], $this->lessonTimes($organization));
    }

    /**
     * O ano letivo é filtrado explicitamente, e não deixado à conta das datas:
     * a exceção abaixo é da mesma organização e cai EXATAMENTE na mesma data, e
     * mesmo assim não bloqueia nada, porque é de outro ano.
     */
    #[Test]
    public function an_exception_of_another_academic_year_never_blocks_the_lesson(): void
    {
        [$teacher, $organization, , $schoolClass] = $this->context();
        $this->slot($organization, $schoolClass, 1);
        $otherYear = $this->inTenant($organization, fn (): AcademicYear => AcademicYear::factory()
            ->recycle($organization)
            ->create(['starts_on' => '2025-09-01', 'ends_on' => '2026-09-30']));
        $this->exception($organization, $otherYear, [
            'title' => 'Feriado do ano anterior',
            'starts_on' => '2026-09-07',
            'ends_on' => '2026-09-07',
        ]);

        $this->materialize($organization, $schoolClass, '2026-09-07', '2026-09-13', $teacher);

        $this->assertSame(['2026-09-07 09:30:00'], $this->lessonTimes($organization));
    }

    /**
     * A GARANTIA CENTRAL DESTA FASE. Uma aula que já existe — já dada, já com
     * sumário — não é apagada nem alterada pela exceção que apareceu depois
     * dela. A guarda impede a criação de uma aula nova e nunca olha para trás:
     * quem quiser desmarcar a aula que já lá está desmarca-a à mão.
     */
    #[Test]
    public function a_lesson_that_already_existed_survives_an_exception_created_afterwards(): void
    {
        [$teacher, $organization, $year, $schoolClass] = $this->context();
        $this->slot($organization, $schoolClass, 1);
        $this->materialize($organization, $schoolClass, '2026-09-07', '2026-09-13', $teacher);

        $before = $this->inTenant($organization, function (): array {
            $lesson = Lesson::query()->sole();
            $lesson->status = LessonStatus::Taught;
            $lesson->save();
            LessonSummary::create(['lesson_id' => $lesson->id, 'content' => 'Sumário já escrito.']);

            // Recuado no tempo de propósito: se a segunda passagem tocasse na
            // linha, o `updated_at` denunciava-a, mesmo que nada mais mudasse.
            DB::table('lessons')->where('id', $lesson->id)->update(['updated_at' => '2020-01-01 00:00:00']);

            return $this->byColumn(Lesson::query()->sole()->getAttributes());
        });

        $this->exception($organization, $year, [
            'type' => AcademicCalendarExceptionType::NonTeachingDay,
            'title' => 'Dia não letivo declarado à posteriori',
            'starts_on' => '2026-09-07',
            'ends_on' => '2026-09-07',
        ]);
        $this->materialize($organization, $schoolClass, '2026-09-07', '2026-09-13', $teacher);

        $this->assertDatabaseCount('lessons', 1);
        $this->assertSame($before, $this->inTenant(
            $organization,
            fn (): array => $this->byColumn(Lesson::query()->sole()->getAttributes()),
        ));
        $this->assertDatabaseCount('lesson_summaries', 1);
    }

    /**
     * Pela porta que o LessonWeekController usa de facto. É a mesma ação por
     * baixo — mas «é a mesma ação por baixo» é uma suposição enquanto ninguém a
     * verificar uma vez de ponta a ponta.
     */
    #[Test]
    public function materializing_a_whole_week_applies_the_guard_end_to_end(): void
    {
        [$teacher, $organization, $year, $schoolClass] = $this->context();
        $this->slot($organization, $schoolClass, 1);
        $this->slot($organization, $schoolClass, 3, '11:30');
        $this->exception($organization, $year, [
            'title' => 'Feriado municipal',
            'starts_on' => '2026-09-07',
            'ends_on' => '2026-09-07',
        ]);

        $this->inTenant($organization, fn () => app(MaterializeLessonsForWeek::class)->execute(
            $teacher,
            $year,
            CarbonImmutable::parse('2026-09-07'),
            CarbonImmutable::parse('2026-09-13'),
        ));

        $this->assertSame(['2026-09-09 11:30:00'], $this->lessonTimes($organization));
    }

    /**
     * @return array{0: User, 1: Organization, 2: AcademicYear, 3: SchoolClass}
     */
    private function context(): array
    {
        $teacher = User::factory()->create();
        $organization = $teacher->personalOrganization();
        $this->subscribeToPro($organization);

        return $this->inTenant($organization, function () use ($teacher, $organization): array {
            $year = AcademicYear::factory()->recycle($organization)->active()->create([
                'starts_on' => '2026-09-01',
                'ends_on' => '2027-06-30',
            ]);
            $schoolClass = SchoolClass::factory()->recycle($organization)->create([
                'academic_year_id' => $year->id,
                'label' => '7.º A',
            ]);
            $schoolClass->teachers()->attach($teacher, ['role' => 'owner']);

            return [$teacher, $organization, $year, $schoolClass];
        });
    }

    private function slot(Organization $organization, SchoolClass $schoolClass, int $weekday, string $startsAt = '09:30'): RecurringLessonSlot
    {
        return $this->inTenant($organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create([
            'class_id' => $schoolClass->id,
            'day_of_week' => $weekday,
            'starts_at' => $startsAt,
            'ends_at' => CarbonImmutable::parse($startsAt)->addMinutes(50)->format('H:i'),
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-30',
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function exception(Organization $organization, AcademicYear $year, array $attributes): AcademicCalendarException
    {
        return $this->inTenant($organization, fn (): AcademicCalendarException => AcademicCalendarException::factory()
            ->recycle($organization)
            ->for($year)
            ->create($attributes));
    }

    /**
     * @return Collection<int, Lesson>
     */
    private function materialize(Organization $organization, SchoolClass $schoolClass, string $from, string $to, User $teacher): Collection
    {
        return $this->inTenant($organization, fn () => app(MaterializeLessonsForRange::class)->execute(
            $schoolClass,
            CarbonImmutable::parse($from),
            CarbonImmutable::parse($to),
            $teacher,
        ));
    }

    /**
     * Os atributos por nome de coluna, para que a comparação seja sobre o que a
     * linha VALE e não sobre a ordem por que o Eloquent a hidratou: um modelo
     * acabado de criar traz as chaves por ordem de escrita, e um relido traz-nas
     * por ordem da tabela. São a mesma linha, e a asserção tem de o dizer.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function byColumn(array $attributes): array
    {
        ksort($attributes);

        return $attributes;
    }

    /**
     * @return list<string>
     */
    private function lessonTimes(Organization $organization): array
    {
        return $this->inTenant($organization, fn (): array => Lesson::query()
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Lesson $lesson): string => $lesson->starts_at->format('Y-m-d H:i:s'))
            ->all());
    }

    private function subscribeToPro(Organization $organization): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->delete();
        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->id,
            'plan_id' => Plan::query()->where('key', 'pro')->firstOrFail()->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);
        app(Entitlements::class)->flush();
    }

    private function inTenant(Organization $organization, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization, $callback);
    }
}
