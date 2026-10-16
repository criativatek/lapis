<?php

namespace App\Services\Lessons;

use App\Models\AcademicYear;
use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Models\LessonStatus;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Classes\ClassIdentityTones;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * A PESQUISA NOS SUMÁRIOS: em que aulas deste ano letivo escrevi isto?
 *
 * SÓ `lesson_summaries.content`. Nunca `private_notes`, `resources` ou
 * `homework`: não são o sumário, e as notas privadas não podem aparecer como
 * se fossem.
 *
 * A CORRESPONDÊNCIA FAZ-SE EM PHP e não num `LIKE` (ver a especificação em
 * docs/superpowers/specs/2026-10-11-lessons-summary-search-design.md): o
 * `LIKE` ignora acentos em MySQL mas não em SQLite, não junta palavras
 * separadas por uma quebra de linha, e aceita equivalências que a contagem e
 * o destaque nunca veriam. SummarySearchText decide tudo — filtro, contagem e
 * destaque.
 *
 * O UNIVERSO É LIMITADO, e é isso que torna isto aceitável: as aulas COM
 * sumário de UM professor num ano letivo (na ordem do milhar), já filtradas
 * em SQL por turma, datas e estado. Lê-se `id` + `content` desse universo e
 * decide-se em memória; só a página pedida carrega o resto.
 */
final class LessonSummarySearch
{
    public const PER_PAGE = 20;

    /** O limite de turmas aceites no filtro: o pedido é um URL. */
    private const MAXIMUM_CLASS_FILTERS = 100;

    public function __construct(
        private readonly LessonRowBuilder $rowBuilder,
        private readonly ClassIdentityTones $identityTones,
    ) {}

    /**
     * @param  list<string>  $classUlids  vazio = todas as turmas do professor
     * @param  list<string>  $states  `taught`, `prepared` e/ou `other` (OU entre eles)
     * @return array{term: string, academic_year: string, classes: list<string>, total: int, occurrences: int, page: int, last_page: int, per_page: int, results: list<array<string, mixed>>}
     */
    public function search(
        User $teacher,
        AcademicYear $academicYear,
        string $term,
        array $classUlids,
        array $states,
        ?CarbonImmutable $from,
        ?CarbonImmutable $to,
        int $page,
    ): array {
        $cleanedTerm = SummarySearchText::cleanTerm($term);
        $normalizedTerm = SummarySearchText::normalize($cleanedTerm);

        // As turmas DESTE professor neste ano, por ulid. Pedidas, é a
        // interseção com elas — e se nada sobrar a resposta é zero, nunca
        // «todas as turmas». A lista entra sempre na consulta, mesmo sem
        // filtro: além de repetir a regra de acesso, é o que deixa o MySQL ir
        // pelo índice da turma em vez de percorrer as aulas da organização.
        /** @var array<string, int> $teacherClasses */
        $teacherClasses = SchoolClass::query()
            ->where('academic_year_id', $academicYear->getKey())
            ->taughtBy($teacher)
            ->pluck('id', 'ulid')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $appliedClasses = $classUlids === []
            ? []
            : array_values(array_intersect(array_slice($classUlids, 0, self::MAXIMUM_CLASS_FILTERS), array_keys($teacherClasses)));
        $empty = $this->payload($cleanedTerm, $academicYear, $appliedClasses, 0, 0, 1, []);

        if ($normalizedTerm === '' || $teacherClasses === [] || ($classUlids !== [] && $appliedClasses === [])) {
            return $empty;
        }

        $query = $this->rowBuilder->accessible($teacher, $academicYear)
            ->whereIn('lessons.class_id', $appliedClasses === []
                ? array_values($teacherClasses)
                : array_map(fn (string $ulid): int => $teacherClasses[$ulid], $appliedClasses));

        // Dias locais da aplicação (Lisboa): o último dia inclui-se inteiro.
        if ($from !== null) {
            $query->where('lessons.starts_at', '>=', $from->startOfDay());
        }

        if ($to !== null) {
            $query->where('lessons.starts_at', '<=', $to->endOfDay());
        }

        $this->restrictToStates($query, $states);

        $rows = $query
            ->join('lesson_summaries', 'lesson_summaries.lesson_id', '=', 'lessons.id')
            ->orderByDesc('lessons.starts_at')
            ->orderByDesc('lessons.id')
            ->toBase()
            ->select(['lessons.id', 'lesson_summaries.content'])
            ->get();

        /** @var array<int, int> $matches lesson id => ocorrências, na ordem de saída */
        $matches = [];

        foreach ($rows as $row) {
            $count = SummarySearchText::occurrences(
                SummarySearchText::normalize((string) $row->content),
                $normalizedTerm,
            );

            if ($count > 0) {
                $matches[(int) $row->id] = $count;
            }
        }

        $total = count($matches);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $lastPage);
        $slice = array_slice($matches, ($page - 1) * self::PER_PAGE, self::PER_PAGE, true);

        return $this->payload(
            $cleanedTerm,
            $academicYear,
            $appliedClasses,
            $total,
            array_sum($matches),
            $page,
            $this->results($teacher, $slice, $normalizedTerm),
            $lastPage,
        );
    }

    /**
     * Os estados pedidos, em OU. As três regras são as da tabela da
     * especificação (`lessons.status` + `lessons.outcome`, 0.146.0): uma aula
     * lecionada pelo estado antigo, sem `outcome`, continua a ser lecionada.
     *
     * @param  Builder<Lesson>  $query
     * @param  list<string>  $states
     */
    private function restrictToStates(Builder $query, array $states): void
    {
        $query->where(function (Builder $group) use ($states): void {
            $recognised = 0;

            foreach (array_unique($states) as $state) {
                $recognised += match ($state) {
                    'taught' => $this->orWhereTaught($group),
                    'prepared' => $this->orWhereOpen($group),
                    'other' => $this->orWhereOtherOutcome($group),
                    default => 0,
                };
            }

            // Sem nenhum estado reconhecido não pode sobrar «todos»: o
            // pedido valida os valores, mas esta classe não confia nisso.
            if ($recognised === 0) {
                $group->whereRaw('1 = 0');
            }
        });
    }

    /** @param  Builder<Lesson>  $group */
    private function orWhereTaught(Builder $group): int
    {
        $group->orWhere(fn (Builder $taught) => $taught
            ->where('lessons.outcome', LessonOutcome::Taught->value)
            ->orWhere(fn (Builder $legacy) => $legacy
                ->whereNull('lessons.outcome')
                ->where('lessons.status', LessonStatus::Taught->value)));

        return 1;
    }

    /** @param  Builder<Lesson>  $group */
    private function orWhereOpen(Builder $group): int
    {
        $group->orWhere(fn (Builder $open) => $open
            ->whereNull('lessons.outcome')
            ->where('lessons.status', '<>', LessonStatus::Taught->value));

        return 1;
    }

    /** @param  Builder<Lesson>  $group */
    private function orWhereOtherOutcome(Builder $group): int
    {
        $group->orWhereIn('lessons.outcome', [
            LessonOutcome::TeacherAbsent->value,
            LessonOutcome::ClassExternalActivity->value,
        ]);

        return 1;
    }

    /**
     * @param  array<int, int>  $slice  lesson id => ocorrências
     * @return list<array<string, mixed>>
     */
    private function results(User $teacher, array $slice, string $normalizedTerm): array
    {
        if ($slice === []) {
            return [];
        }

        $lessons = Lesson::query()
            ->whereIn('id', array_keys($slice))
            ->with([
                'schoolClass.subject',
                'classGroup',
                // Só o que a linha lê: nunca as notas privadas nem os recursos.
                'summary' => fn (Relation $query) => $query->select(['id', 'lesson_id', 'content']),
            ])
            ->get()
            ->keyBy(fn (Lesson $lesson): int => (int) $lesson->getKey());

        // Uma chamada para a página inteira, e nunca uma por resultado.
        $tones = $this->identityTones->forTeacher(
            (int) $teacher->getKey(),
            array_values(array_unique($lessons->pluck('class_id')->map(fn ($id): int => (int) $id)->all())),
        );

        $results = [];

        foreach ($slice as $lessonId => $occurrences) {
            $lesson = $lessons->get($lessonId);

            if ($lesson === null) {
                continue;
            }

            $results[] = [
                'ulid' => $lesson->ulid,
                'starts_at' => $lesson->starts_at->toIso8601String(),
                'ends_at' => $lesson->ends_at?->toIso8601String(),
                'school_class' => [
                    'ulid' => $lesson->schoolClass->ulid,
                    'label' => $lesson->schoolClass->label,
                    'is_support_class' => (bool) $lesson->schoolClass->is_support_class,
                ],
                'context_label' => $lesson->contextLabel(),
                'class_group_id' => $lesson->class_group_id,
                'class_group_label' => $lesson->classGroup?->label,
                'subject' => $lesson->schoolClass->subject->name,
                'status' => $lesson->status->value,
                'status_label' => $lesson->status->label(),
                'outcome' => $lesson->outcome?->value,
                'outcome_label' => $lesson->outcome?->label(),
                'lesson_number' => $lesson->lesson_number,
                'lesson_number_end' => $lesson->lesson_number_end,
                'identity_tone' => $tones[$lesson->class_id] ?? null,
                'occurrences' => $occurrences,
                // O sumário COMPLETO, partido em segmentos de texto — nunca HTML.
                'segments' => SummarySearchText::segments(trim((string) $lesson->summary?->content), $normalizedTerm),
            ];
        }

        return $results;
    }

    /**
     * O âmbito que foi EFETIVAMENTE pesquisado viaja com a resposta — o ano
     * letivo da sessão e as turmas aplicadas —, para o ecrã nunca escrever
     * por cima dos resultados um âmbito que não foi o usado (outro separador
     * pode ter mudado o ano letivo entretanto).
     *
     * @param  list<string>  $classes
     * @param  list<array<string, mixed>>  $results
     * @return array{term: string, academic_year: string, classes: list<string>, total: int, occurrences: int, page: int, last_page: int, per_page: int, results: list<array<string, mixed>>}
     */
    private function payload(string $term, AcademicYear $academicYear, array $classes, int $total, int $occurrences, int $page, array $results, int $lastPage = 1): array
    {
        return [
            'term' => $term,
            'academic_year' => (string) $academicYear->label,
            'classes' => $classes,
            'total' => $total,
            'occurrences' => $occurrences,
            'page' => $page,
            'last_page' => $lastPage,
            'per_page' => self::PER_PAGE,
            'results' => $results,
        ];
    }
}
