<?php

namespace App\Http\Controllers;

use App\Http\Requests\Calendar\CalendarMonthRequest;
use App\Models\AcademicPeriodKind;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Calendar\AcademicYearCalendarQuery;
use App\Support\Retention\ResolveSelectedAcademicYear;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Calendário do Ano Letivo» — the year's own shape, its avaliações, the
 * teacher's own acontecimentos e os dias em que NÃO há aula, read together, in
 * two views: Mês and Ano.
 *
 * NOTHING HERE IS PERSISTED. Both views are readings — of AcademicPeriod, of
 * Instrument.applied_on, (Fase 5.3) of the teacher's own CalendarEvent rows, e
 * (Fase 5.4) das exceções letivas do ano, que se criam e alteram em «Estrutura
 * do Ano Letivo», ao lado dos períodos, e nunca aqui. Opening, navigating or
 * refreshing either view creates nothing at all;
 * an acontecimento only ever comes into being through an explicit action of
 * the teacher's, in CalendarEventController, which is a different class
 * precisely so that this one can go on being only a reading.
 *
 * AULAS DO NOT APPEAR HERE, deliberately. «Horário do Professor» already
 * answers «que aulas tenho, quando e onde»; this page answers «o que é
 * relevante no meu ano». Showing aulas would collapse the two into one page,
 * so no Lesson and no RecurringLessonSlot is read, counted or hinted at — and,
 * unlike «Aulas e Sumários», nothing is ever materialized by looking.
 *
 * Being GET-only and side-effect free, it needs no impersonation refusal — the
 * same reasoning TeacherTimetableController already applies to its own reading.
 * A support session may look at the calendar it is being asked about, because
 * looking changes nothing.
 *
 * Which year is «the» year is not decided here: it is ResolveSelectedAcademicYear,
 * the one canonical answer «Aulas e Sumários» already uses, asked the same way.
 */
class AcademicYearCalendarController extends Controller implements HasMiddleware
{
    private const TIMEZONE = 'Europe/Lisbon';

    /**
     * How many ITEMS a day cell shows before it stops growing and offers the
     * rest behind one control. A cell that lists everything turns a week with a
     * test-heavy Friday into a column nothing else fits beside.
     *
     * ITEMS, and no longer avaliações alone (Fase 5.3): a day with two
     * avaliações and three acontecimentos is exactly as crowded as a day with
     * five avaliações, and a cap that counted only one of the two kinds would
     * let the cell grow without bound as soon as the other kind arrived. One
     * cap, one «+N mais», over both — never a second overflow mechanism beside
     * the first.
     */
    private const ITEMS_PER_DAY = 3;

    public function __construct(
        private readonly AcademicYearCalendarQuery $calendar,
        private readonly ResolveSelectedAcademicYear $resolveAcademicYear,
    ) {}

    /**
     * Gated by module:calendar — the entitlement this menu entry has always
     * carried, and which existed long before there was a page behind it. No new
     * capability is invented for a page that only reads data the teacher can
     * already reach elsewhere.
     *
     * @return list<string>
     */
    public static function middleware(): array
    {
        return ['module:calendar'];
    }

    public function index(CalendarMonthRequest $request): Response
    {
        $academicYear = $this->selectedAcademicYear($request);

        if ($academicYear === null) {
            return Inertia::render('calendar/Month', [
                'academicYear' => null,
                'month' => null,
                'days' => [],
                'periods' => [],
                'exceptions' => [],
                'navigation' => null,
                'itemsPerDay' => self::ITEMS_PER_DAY,
                'classes' => [],
            ]);
        }

        $requestedMonth = $request->validated('month');
        $month = is_string($requestedMonth)
            ? CarbonImmutable::createFromFormat('Y-m-d', $requestedMonth.'-01', self::TIMEZONE)->startOfMonth()->startOfDay()
            : $this->openingMonth($academicYear);

        // The grid's first row reaches back into the previous month and its
        // last row into the next, so the range read is the GRID's, not the
        // month's: an avaliação on a visible leading or trailing day belongs in
        // the cell it is shown in, not silently missing from it.
        $gridStart = $month->startOfWeek(CarbonImmutable::MONDAY);
        $gridEnd = $month->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY);

        // O mês que está a ser visto viaja com cada avaliação, para que a página
        // do elemento saiba oferecer «← Voltar ao Calendário» exatamente para
        // este mês. É o mesmo mecanismo — uma query string, lida no cliente —
        // que «Avaliações» já usa, e não um returnTo servido pelo servidor.
        $reading = $this->calendar->for($this->user($request), $academicYear, $gridStart, $gridEnd, $month->format('Y-m'));
        $opening = $this->openingMonth($academicYear);

        return Inertia::render('calendar/Month', [
            'academicYear' => $this->academicYearPayload($academicYear),
            'month' => [
                'value' => $month->format('Y-m'),
                'starts_on' => $month->toDateString(),
                'ends_on' => $month->endOfMonth()->toDateString(),
            ],
            'days' => $this->days($month, $gridStart, $gridEnd, $reading),
            // The períodos crossing this month, for the legend above the grid —
            // the same rows the cells below are banded from, so the two can
            // never describe the month differently.
            'periods' => $reading['periods'],
            // AS EXCEÇÕES QUE CRUZAM A GRELHA, nomeadas UMA VEZ por cima dela e
            // não uma vez por cada dia que ocupam: uma interrupção de 21 a 31 de
            // dezembro é UMA coisa com onze dias, e não onze coisas. As células
            // por baixo dizem quais são esses dias com o seu próprio tom, e é
            // desta mesma lista que sabem qual é a exceção que as cobre.
            'exceptions' => $reading['exceptions'],
            'navigation' => [
                'previous' => $month->subMonthNoOverflow()->format('Y-m'),
                'next' => $month->addMonthNoOverflow()->format('Y-m'),
                // Where the calendar opens with no parameter at all. Labelled
                // «Mês atual» only when today really is inside this year;
                // otherwise it is honestly the year's first month.
                'home' => $opening->format('Y-m'),
                'home_is_today' => $opening->isSameMonth($this->today()),
            ],
            'itemsPerDay' => self::ITEMS_PER_DAY,
            // As turmas deste professor, para o formulário de «Novo
            // acontecimento» poder oferecer as suas e só as suas. É a MESMA
            // pergunta — SchoolClass::scopeTaughtBy — que «Horário do
            // Professor» e as Turmas já fazem, feita aqui e não reescrita, para
            // que a lista oferecida não possa divergir da lista que
            // CalendarEventRequest aceita.
            'classes' => $this->teacherClasses($this->user($request)),
        ]);
    }

    /**
     * The Ano view — the whole year at a glance: its períodos as bands, how
     * many avaliações fall in each month and in each período, and how many
     * acontecimentos cross each month.
     *
     * COUNTS AND NOT A LIST, on purpose, and that rule is unchanged by Fase 5.3
     * — it now simply also counts acontecimentos. A year's avaliações itemized
     * end to end is the Elementos de Avaliação listing, which already exists;
     * what is missing at this scale is the shape of the year, and a hundred
     * rows would bury it. There is no day grid here either, for the same
     * reason, and no acontecimento is named at this scale: the Mês view, one
     * click away on every month tile, is where they are read one by one.
     */
    public function year(Request $request): Response
    {
        $academicYear = $this->selectedAcademicYear($request);

        if ($academicYear === null) {
            return Inertia::render('calendar/Year', [
                'academicYear' => null,
                'months' => [],
                'periods' => [],
                'exceptions' => [],
                'periodsCountLabel' => $this->periodsCountLabel([]),
                'assessmentsTotal' => 0,
                'eventsTotal' => 0,
                'nonTeachingDaysTotal' => 0,
            ]);
        }

        $startsOn = $this->date($academicYear->starts_on->toDateString());
        $endsOn = $this->date($academicYear->ends_on->toDateString());

        $reading = $this->calendar->for($this->user($request), $academicYear, $startsOn, $endsOn);

        $months = [];
        $cursor = $startsOn->startOfMonth();
        $lastMonth = $endsOn->startOfMonth();

        while ($cursor->lessThanOrEqualTo($lastMonth)) {
            $monthStart = $cursor->toDateString();
            $monthEnd = $cursor->endOfMonth()->toDateString();

            $months[] = [
                'value' => $cursor->format('Y-m'),
                'starts_on' => $monthStart,
                // O último dia do mês vai escrito, e não só o primeiro: sem ele
                // a vista não consegue distinguir um mês INTEIRAMENTE dentro de
                // um período de um mês que o período apenas toca — e pintar o
                // mês todo com a cor de um período que só lá está metade é a
                // única coisa que este cartão nunca pode fazer.
                'ends_on' => $monthEnd,
                'assessments_count' => count(array_filter(
                    $reading['assessments'],
                    fn (array $assessment): bool => $assessment['applied_on'] >= $monthStart && $assessment['applied_on'] <= $monthEnd,
                )),
                // Um acontecimento CRUZA um mês, e não «cai» nele: uma visita
                // de estudo de 30 de outubro a 2 de novembro é uma coisa que
                // acontece nos dois meses, e conta-se nos dois — a mesma
                // semântica de sobreposição que as faixas dos períodos, logo a
                // seguir, já usam para a mesma pergunta.
                'events_count' => count(array_filter(
                    $reading['events'],
                    fn (array $event): bool => $event['starts_on'] <= $monthEnd && $event['ends_on'] >= $monthStart,
                )),
                // Which bands cross this month — a month may sit in two, when a
                // período ends partway through it and the next begins.
                'period_ulids' => array_values(array_map(
                    fn (array $period): string => $period['ulid'],
                    array_filter(
                        $reading['periods'],
                        fn (array $period): bool => $period['starts_on'] <= $monthEnd && $period['ends_on'] >= $monthStart,
                    ),
                )),
                // AS EXCEÇÕES QUE CRUZAM ESTE MÊS, pelos seus ulids — a mesma
                // forma que os `period_ulids` acima, e pela mesma razão: uma
                // interrupção de 21 de dezembro a 3 de janeiro é estrutura dos
                // dois meses, e conta-se nos dois. Vão os ulids e não os objetos
                // porque os objetos vão inteiros uma só vez, em `exceptions`.
                'exception_ulids' => array_values(array_map(
                    fn (array $exception): string => $exception['ulid'],
                    array_filter(
                        $reading['exceptions'],
                        fn (array $exception): bool => $exception['starts_on'] <= $monthEnd && $exception['ends_on'] >= $monthStart,
                    ),
                )),
                // E QUANTOS DIAS DESTE MÊS SÃO MESMO NÃO LETIVOS. Contar as
                // exceções — «2 exceções em dezembro» — não diria nada a
                // ninguém: uma delas pode ser um feriado e a outra onze dias de
                // interrupção. Contam-se DIAS, que é a única coisa que a esta
                // escala responde à pergunta que se faz a um mês.
                'non_teaching_days_count' => $this->nonTeachingDays($reading['exceptions'], $monthStart, $monthEnd),
                'is_current' => $cursor->isSameMonth($this->today()),
            ];

            $cursor = $cursor->addMonthNoOverflow();
        }

        return Inertia::render('calendar/Year', [
            'academicYear' => $this->academicYearPayload($academicYear),
            'months' => $months,
            // «2 semestres», «3 períodos» — contado e NOMEADO aqui, onde a
            // espécie de cada período está escrita, e não adivinhado na página.
            'periodsCountLabel' => $this->periodsCountLabel($reading['periods']),
            'periods' => array_map(
                fn (array $period): array => [
                    ...$period,
                    'assessments_count' => count(array_filter(
                        $reading['assessments'],
                        fn (array $assessment): bool => $assessment['applied_on'] >= $period['starts_on'] && $assessment['applied_on'] <= $period['ends_on'],
                    )),
                ],
                $reading['periods'],
            ),
            // AS EXCEÇÕES INTEIRAS, UMA VEZ SÓ — como os períodos acima, e não
            // como os acontecimentos, que a esta escala são apenas contados.
            // A razão é que a vista de Ano precisa de as NOMEAR: um mês com
            // dias não letivos tem de poder dizer quais são, e um número
            // sozinho («11 dias») não distingue uma interrupção de Natal de
            // onze feriados espalhados. Continua a não haver aqui um cartão por
            // exceção — os cartões são os meses, e é dentro deles que estas
            // aparecem.
            'exceptions' => $reading['exceptions'],
            'assessmentsTotal' => count($reading['assessments']),
            'eventsTotal' => count($reading['events']),
            // Dias, e não exceções, pela mesma razão que nos meses: é a única
            // contagem que significa a mesma coisa qualquer que seja a mistura
            // de feriados e interrupções que o ano tenha.
            'nonTeachingDaysTotal' => $this->nonTeachingDays(
                $reading['exceptions'],
                $startsOn->toDateString(),
                $endsOn->toDateString(),
            ),
        ]);
    }

    /**
     * QUANTOS DIAS DISTINTOS deste intervalo são não letivos.
     *
     * DISTINTOS, e é aí que está o trabalho: um feriado que calha dentro de uma
     * interrupção letiva é UM dia não letivo e não dois, e somar a duração de
     * cada exceção contá-lo-ia duas vezes. Por isso os dias vão para um
     * conjunto, e é o conjunto que se conta.
     *
     * E RECORTADOS AO INTERVALO: uma interrupção de 21 de dezembro a 3 de
     * janeiro dá nove dias a dezembro e três a janeiro, nunca doze a cada um.
     *
     * @param  list<array<string, mixed>>  $exceptions
     */
    private function nonTeachingDays(array $exceptions, string $from, string $to): int
    {
        $days = [];

        foreach ($exceptions as $exception) {
            $start = max((string) $exception['starts_on'], $from);
            $end = min((string) $exception['ends_on'], $to);

            if ($start > $end) {
                continue;
            }

            for ($day = $this->date($start); $day->toDateString() <= $end; $day = $day->addDay()) {
                $days[$day->toDateString()] = true;
            }
        }

        return count($days);
    }

    /**
     * HOW MANY, AND OF WHAT SHAPE — «2 semestres», «3 períodos», «1 trimestre».
     *
     * The collective noun is read from the períodos' own `kind`, which is where
     * this application already writes down what shape a year has: nothing is
     * inferred from a label's text, and nothing is guessed. When the year's
     * períodos are all of one kind, that kind names them — lower-cased for the
     * middle of a sentence, the exact inverse of the frontend's capitalizeFirst.
     *
     * WHEN THE KINDS DIFFER, NOTHING IS INVENTED: a year mixing semestres and
     * períodos is named by the neutral word that is structurally true of all of
     * them — «período», which is the model's own name — rather than by whichever
     * kind happened to come first.
     *
     * @param  list<array<string, mixed>>  $periods
     */
    private function periodsCountLabel(array $periods): string
    {
        $count = count($periods);
        $kinds = array_unique(array_column($periods, 'kind'));

        if (count($kinds) !== 1) {
            return $count === 1 ? '1 período' : "{$count} períodos";
        }

        $kind = AcademicPeriodKind::from((string) reset($kinds));
        $noun = $count === 1 ? $kind->label() : $kind->pluralLabel();

        return "{$count} ".lcfirst($noun);
    }

    /**
     * One cell per day of the grid, each carrying the período it falls in (or
     * none, honestly, when the year leaves a gap between two), the avaliações
     * applied on it, and the acontecimentos that cover it.
     *
     * COVER, and not «begin on»: an acontecimento that runs from Monday to
     * Thursday is what is happening on each of those four days, so it appears
     * in each of the four cells — the same reading the período band above it
     * already gets. An avaliação, by contrast, has one date and appears once.
     *
     * E A EXCEÇÃO LETIVA que o cobre, quando há uma (Fase 5.4). É a MESMA forma
     * do período — uma coisa por dia, ou nenhuma, e nunca uma lista — e não a
     * dos acontecimentos, de propósito: uma interrupção de onze dias tem de se
     * ler como UM intervalo com nome, e não como onze cartões repetidos dentro
     * de onze células. A página tinge o dia e nomeia-a onde ela começa.
     *
     * @param  array{periods: list<array<string, mixed>>, assessments: list<array<string, mixed>>, events: list<array<string, mixed>>, exceptions: list<array<string, mixed>>}  $reading
     * @return list<array{date: string, day: int, in_month: bool, is_today: bool, period: array<string, mixed>|null, exception: array<string, mixed>|null, assessments: list<array<string, mixed>>, events: list<array<string, mixed>>}>
     */
    private function days(CarbonImmutable $month, CarbonImmutable $gridStart, CarbonImmutable $gridEnd, array $reading): array
    {
        $today = $this->today()->toDateString();
        $days = [];

        for ($day = $gridStart; $day->lessThanOrEqualTo($gridEnd); $day = $day->addDay()) {
            $date = $day->toDateString();

            $period = null;
            foreach ($reading['periods'] as $candidate) {
                if ($candidate['starts_on'] <= $date && $candidate['ends_on'] >= $date) {
                    $period = $candidate;
                    break;
                }
            }

            // A EXCEÇÃO QUE COBRE ESTE DIA, exatamente como o período acima — e
            // a PRIMEIRA delas, quando por acaso houver duas (um feriado que
            // calha dentro de uma interrupção). Uma célula tem espaço para dizer
            // uma coisa sobre si própria, e as duas dizem o mesmo: não há aula.
            // A lista completa vai na faixa por cima da grelha, onde há espaço.
            $exception = null;
            foreach ($reading['exceptions'] as $candidate) {
                if ($candidate['starts_on'] <= $date && $candidate['ends_on'] >= $date) {
                    $exception = $candidate;
                    break;
                }
            }

            $days[] = [
                'date' => $date,
                'day' => $day->day,
                'in_month' => $day->isSameMonth($month),
                'is_today' => $date === $today,
                'period' => $period,
                'exception' => $exception,
                'assessments' => array_values(array_filter(
                    $reading['assessments'],
                    fn (array $assessment): bool => $assessment['applied_on'] === $date,
                )),
                'events' => array_values(array_filter(
                    $reading['events'],
                    fn (array $event): bool => $event['starts_on'] <= $date && $event['ends_on'] >= $date,
                )),
            ];
        }

        return $days;
    }

    /**
     * As turmas que este professor leciona, para o formulário de acontecimentos.
     *
     * A MESMA PERGUNTA, FEITA UMA VEZ. SchoolClass::scopeTaughtBy é a única
     * resposta a «que turmas são deste professor» neste projeto — a que as
     * Turmas, o «Horário do Professor» e as avaliações deste mesmo calendário
     * já usam. Chamada aqui e não reescrita, para que o que o formulário
     * oferece e o que CalendarEventRequest aceita não possam divergir.
     *
     * @return list<array{ulid: string, label: string, subject: string}>
     */
    private function teacherClasses(User $teacher): array
    {
        return array_values(SchoolClass::query()
            ->taughtBy($teacher)
            ->with('subject')
            ->orderBy('label')
            ->get()
            ->map(fn (SchoolClass $schoolClass): array => [
                'ulid' => $schoolClass->ulid,
                'label' => $schoolClass->label,
                'subject' => $schoolClass->subject->name,
            ])
            ->all());
    }

    /**
     * WHICH MONTH THE CALENDAR OPENS ON, when no month is asked for: today's
     * month if today falls inside the year, and otherwise the month the year
     * itself begins in. Opening a finished — or a not-yet-started — year on
     * today's empty month would show a correct calendar of nothing at all.
     */
    private function openingMonth(AcademicYear $academicYear): CarbonImmutable
    {
        $today = $this->today();
        $startsOn = $this->date($academicYear->starts_on->toDateString());
        $endsOn = $this->date($academicYear->ends_on->toDateString());

        return $today->greaterThanOrEqualTo($startsOn) && $today->lessThanOrEqualTo($endsOn)
            ? $today->startOfMonth()
            : $startsOn->startOfMonth();
    }

    /** @return array{ulid: string, label: string, starts_on: string, ends_on: string} */
    private function academicYearPayload(AcademicYear $academicYear): array
    {
        return [
            'ulid' => $academicYear->ulid,
            'label' => $academicYear->label,
            'starts_on' => $academicYear->starts_on->toDateString(),
            'ends_on' => $academicYear->ends_on->toDateString(),
        ];
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE)->startOfDay();
    }

    private function date(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $date, self::TIMEZONE)->startOfDay();
    }

    /**
     * The one canonical answer to «which year is the teacher working in»,
     * asked exactly as LessonWeekController asks it — same service, same
     * ordering, same session key — so the calendar and the week can never
     * disagree about which year they are showing.
     */
    private function selectedAcademicYear(Request $request): ?AcademicYear
    {
        $years = AcademicYear::query()->orderByDesc('starts_on')->get();
        $selectedId = $request->session()->get('academic_year_id');

        return $this->resolveAcademicYear->for($years, is_int($selectedId) ? $selectedId : null);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
