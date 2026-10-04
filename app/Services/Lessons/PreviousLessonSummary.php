<?php

namespace App\Services\Lessons;

use App\Models\AcademicYear;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * «O que demos da última vez» — por faixa (a turma inteira ou um grupo), a
 * última aula ANTERIOR a um instante que tenha texto de sumário.
 *
 * SÓ LEITURA, e do MESMO GRUPO: a mesma razão de
 * LessonController::previousSummary — T1 e T2 avançam a ritmos diferentes, e
 * o anterior de um grupo nunca é o de outro. Sem anterior da faixa, a resposta
 * é «nenhum», e não o de outra faixa por não haver melhor. Esta pergunta não
 * cria, materializa nem altera aulas.
 */
final class PreviousLessonSummary
{
    /** Quantas candidatas se leem por faixa antes de desistir de achar texto não vazio. */
    private const CANDIDATES = 10;

    public function __construct(private readonly LessonRowBuilder $rowBuilder) {}

    /**
     * @param  list<array{group_id: int|null, group_label: string|null}>  $lanes
     * @return list<array{group_id: int|null, group_label: string|null, lesson: array<string, mixed>|null}>
     */
    public function for(User $teacher, AcademicYear $academicYear, SchoolClass $class, array $lanes, CarbonImmutable $before): array
    {
        /** @var array<int, Lesson|null> $found indexado pela posição da faixa */
        $found = [];

        foreach ($lanes as $position => $lane) {
            $found[$position] = $this->rowBuilder->query($teacher, $academicYear)
                ->where('class_id', $class->getKey())
                ->where(fn ($query) => $lane['group_id'] === null
                    ? $query->whereNull('class_group_id')
                    : $query->where('class_group_id', $lane['group_id']))
                ->where('starts_at', '<', $before)
                ->whereHas('summary', fn ($query) => $query->whereRaw("TRIM(content) <> ''"))
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->limit(self::CANDIDATES)
                ->get()
                // Aparar em PHP é a regra de «tem texto» da linha (`summary`
                // NULL quando vazio); o TRIM da base de dados só poupa
                // candidatas e não apanha, por exemplo, só quebras de linha.
                ->first(fn (Lesson $lesson): bool => trim((string) $lesson->summary?->content) !== '');
        }

        // Uma só construção de linhas para todas as faixas: os tons e os
        // acontecimentos do dia vêm numa consulta cada, e não por faixa.
        $rowsByUlid = [];

        foreach ($this->rowBuilder->rows(array_values(array_filter($found)), $teacher) as $row) {
            $rowsByUlid[(string) $row['ulid']] = $row;
        }

        return array_map(fn (array $lane, int $position): array => [
            'group_id' => $lane['group_id'],
            'group_label' => $lane['group_label'],
            'lesson' => isset($found[$position]) ? ($rowsByUlid[$found[$position]->ulid] ?? null) : null,
        ], $lanes, array_keys($lanes));
    }
}
