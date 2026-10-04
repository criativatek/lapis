<?php

namespace App\Services\Lessons;

use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Classes\ClassIdentityTones;
use Carbon\CarbonImmutable;

/**
 * Os dados da vista «por turma» de Aulas e Sumários: a lista de turmas do
 * professor e, para UMA delas, as aulas de um intervalo de semanas.
 *
 * SÓ LEITURA. Esta vista nunca cria, materializa nem altera aulas: abrir a
 * semana já materializou a semana selecionada (LessonWeekController::index), e
 * um intervalo de quatro semanas ou do ano inteiro mostra o que existe — não
 * traz ao mundo aulas que o horário ainda não produziu. As linhas vêm do mesmo
 * construtor que a semana usa (LessonRowBuilder), e é isso que garante que as
 * duas vistas não discordam.
 */
final class ClassLessonsView
{
    public const RANGES = ['1', '2', '4', 'ano'];

    private const TIMEZONE = 'Europe/Lisbon';

    public function __construct(
        private readonly LessonRowBuilder $rowBuilder,
        private readonly PreviousLessonSummary $previousSummary,
        private readonly ClassIdentityTones $identityTones,
    ) {}

    /**
     * Todas as turmas que o professor leciona neste ano letivo — arquivadas
     * incluídas, marcadas como tal —, por ordem do rótulo.
     *
     * @return list<array{ulid: string, label: string, subject: string, is_support_class: bool, archived: bool, identity_tone: string|null, groups: list<array{id: int, label: string}>}>
     */
    public function classes(User $teacher, AcademicYear $academicYear): array
    {
        $schoolClasses = SchoolClass::query()
            ->where('academic_year_id', $academicYear->getKey())
            ->taughtBy($teacher)
            // Só os grupos ATIVOS: oferecer um grupo arquivado como filtro
            // seria o mesmo beco que o resto da aplicação já recusa abrir. As
            // aulas dele continuam a aparecer em «todos».
            ->with(['subject', 'classGroups' => fn ($query) => $query->active()->orderBy('position')->orderBy('id')])
            ->orderBy('label')
            ->get();

        $tones = $this->identityTones->forTeacher(
            (int) $teacher->getKey(),
            $schoolClasses->modelKeys(),
        );

        return array_values($schoolClasses->map(fn (SchoolClass $schoolClass): array => [
            'ulid' => $schoolClass->ulid,
            'label' => $schoolClass->label,
            'subject' => $schoolClass->subject->name,
            'is_support_class' => (bool) $schoolClass->is_support_class,
            'archived' => $schoolClass->archived_at !== null,
            'identity_tone' => $tones[$schoolClass->id] ?? null,
            'groups' => $this->groups($schoolClass),
        ])->all());
    }

    /**
     * A prop `classView`. `$classes` é a lista já calculada por `classes()`;
     * NULL quando o professor não leciona nenhuma turma neste ano.
     *
     * @param  list<array<string, mixed>>  $classes
     * @return array<string, mixed>|null
     */
    public function for(
        User $teacher,
        AcademicYear $academicYear,
        array $classes,
        ?string $requestedClass,
        string $requestedGroup,
        string $requestedRange,
        CarbonImmutable $weekStart,
    ): ?array {
        if ($classes === []) {
            return null;
        }

        // A turma pedida só vale se o professor a leciona NESTE ano; qualquer
        // outra coisa (ulid desconhecido, de outro professor ou de outro ano)
        // cai na primeira da lista, sem erro.
        $selected = $classes[0];

        foreach ($classes as $candidate) {
            if ($candidate['ulid'] === $requestedClass) {
                $selected = $candidate;
                break;
            }
        }

        $schoolClass = SchoolClass::query()->where('ulid', $selected['ulid'])->firstOrFail();

        /** @var array<int, string> $groupLabels todos os grupos da turma, arquivados incluídos */
        $groupLabels = ClassGroup::query()
            ->where('class_id', $schoolClass->getKey())
            ->pluck('label', 'id')
            ->all();

        $group = $this->resolveGroup($requestedGroup, $groupLabels);
        $range = in_array($requestedRange, self::RANGES, true) ? $requestedRange : '1';
        [$start, $end, $clamped] = $this->bounds($academicYear, $range, $weekStart);

        $lessons = $this->rowBuilder->query($teacher, $academicYear)
            ->where('class_id', $schoolClass->getKey())
            ->when($group === 'inteira', fn ($query) => $query->whereNull('class_group_id'))
            ->when(ctype_digit($group), fn ($query) => $query->where('class_group_id', (int) $group))
            ->whereBetween('starts_at', [$start->startOfDay(), $end->endOfDay()])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        return [
            'class' => [
                'ulid' => $selected['ulid'],
                'label' => $selected['label'],
                'subject' => $selected['subject'],
                'is_support_class' => $selected['is_support_class'],
                'identity_tone' => $selected['identity_tone'],
                'groups' => $selected['groups'],
            ],
            'group' => $group,
            'range' => [
                'key' => $range,
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'clamped' => $clamped,
            ],
            'lessons' => $this->rowBuilder->rows($lessons, $teacher),
            'previous' => $this->previousSummary->for(
                $teacher,
                $academicYear,
                $schoolClass,
                $this->lanes($group, $selected['groups'], $groupLabels),
                $start->startOfDay(),
            ),
        ];
    }

    /**
     * 'todos' | 'inteira' | o id de um grupo DA TURMA. Qualquer outra coisa —
     * incluindo o id de um grupo de outra turma — conta como 'todos'.
     *
     * @param  array<int, string>  $groupLabels
     */
    private function resolveGroup(string $requested, array $groupLabels): string
    {
        if ($requested === 'inteira') {
            return 'inteira';
        }

        if (ctype_digit($requested) && array_key_exists((int) $requested, $groupLabels)) {
            return (string) (int) $requested;
        }

        return 'todos';
    }

    /**
     * O intervalo: acaba no domingo da semana selecionada e recua n−1 semanas
     * (ou vai ao início do ano letivo, em 'ano'); nunca antes do início do ano.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: bool}
     */
    private function bounds(AcademicYear $academicYear, string $range, CarbonImmutable $weekStart): array
    {
        $yearStart = CarbonImmutable::parse($academicYear->starts_on->toDateString(), self::TIMEZONE)->startOfDay();
        $end = $weekStart->setTimezone(self::TIMEZONE)->startOfWeek()->addDays(6)->startOfDay();

        if ($range === 'ano') {
            return [$yearStart, $end, false];
        }

        $start = $weekStart->setTimezone(self::TIMEZONE)->startOfWeek()->subDays(7 * ((int) $range - 1))->startOfDay();

        return $start->lessThan($yearStart) ? [$yearStart, $end, true] : [$start, $end, false];
    }

    /**
     * As faixas de «o que demos da última vez»: a turma inteira (group_id
     * NULL) e/ou grupos, conforme o filtro.
     *
     * @param  list<array{id: int, label: string}>  $activeGroups
     * @param  array<int, string>  $groupLabels
     * @return list<array{group_id: int|null, group_label: string|null}>
     */
    private function lanes(string $group, array $activeGroups, array $groupLabels): array
    {
        $whole = ['group_id' => null, 'group_label' => null];

        if ($group === 'inteira') {
            return [$whole];
        }

        if (ctype_digit($group)) {
            return [['group_id' => (int) $group, 'group_label' => $groupLabels[(int) $group] ?? null]];
        }

        return [
            $whole,
            ...array_map(
                fn (array $activeGroup): array => ['group_id' => $activeGroup['id'], 'group_label' => $activeGroup['label']],
                $activeGroups,
            ),
        ];
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function groups(SchoolClass $schoolClass): array
    {
        return array_values($schoolClass->classGroups
            ->map(fn (ClassGroup $group): array => [
                'id' => (int) $group->getKey(),
                'label' => (string) $group->label,
            ])
            ->all());
    }
}
