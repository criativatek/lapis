<?php

namespace App\Support\Lessons;

use App\Actions\Lessons\RectifyLessonOutcome;
use App\Models\Lesson;
use App\Models\LessonNotHeldCause;
use App\Models\LessonOutcome;
use App\Models\LessonOutcomeRectification;
use App\Models\TeacherAbsenceReason;
use App\Models\TeacherStrikeSituation;
use App\Models\User;

/**
 * O que o ecrã da aula recebe para CORRIGIR o resultado: o valor atual, para onde se pode corrigir, e o
 * histórico das correções já feitas, já em texto legível.
 *
 * Só chega a quem pode corrigir (LessonPolicy::rectifyOutcome — o titular do registo): o valor atual e o
 * histórico podem conter dado reservado. Para toda a gente, incluindo colegas da turma, é `null`.
 */
final class LessonOutcomeRectificationView
{
    /**
     * @return array{
     *     current: array<string, string|null>,
     *     targets: list<string>,
     *     outcome_locked_reason: string|null,
     *     history: list<array{ulid: string, at: string, by: string|null, kind: string, by_direction: bool, motive: string, changes: list<array{field: string, label: string, from: string, to: string}>}>
     * }|null
     */
    public static function for(Lesson $lesson, User $viewer, RectifyLessonOutcome $rectify): ?array
    {
        if ($lesson->outcome === null || ! $viewer->can('rectifyOutcome', $lesson)) {
            return null;
        }

        return [
            'current' => $rectify->snapshot($lesson),
            'targets' => array_map(fn (LessonOutcome $outcome): string => $outcome->value, RectifyLessonOutcome::targetsFor($lesson->outcome)),
            'outcome_locked_reason' => RectifyLessonOutcome::outcomeLockedReason($lesson->outcome),
            'history' => array_values(LessonOutcomeRectification::query()
                ->where('lesson_id', $lesson->getKey())
                ->with('rectifier')
                ->orderByDesc('rectified_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (LessonOutcomeRectification $rectification): array => [
                    'ulid' => $rectification->ulid,
                    'at' => $rectification->rectified_at->toIso8601String(),
                    'by' => $rectification->rectifier?->name,
                    'kind' => $rectification->kind,
                    // Anulação feita por quem não é o titular do fecho: a direção.
                    'by_direction' => $rectification->kind === LessonOutcomeRectification::KIND_ANNULMENT
                        && (int) $rectification->rectified_by !== (int) $lesson->outcome_recorded_by,
                    'motive' => $rectification->motive,
                    'changes' => self::changes($rectification),
                ])
                ->all()),
        ];
    }

    /**
     * As linhas legíveis de uma correção. Numa ANULAÇÃO (0.194.0) acrescentam-se o número que a aula
     * tinha e, quando o sumário não pôde descer, o texto removido — este só com `$includeSummary`,
     * que a lista da direção desliga (ela não lê o sumário de ninguém).
     *
     * @return list<array{field: string, label: string, from: string, to: string}>
     */
    public static function changes(LessonOutcomeRectification $rectification, bool $includeSummary = true): array
    {
        $labels = [
            'outcome' => __('Resultado'),
            'reason' => __('Motivo da ausência'),
            'note' => __('Descrição da atividade'),
            'cause' => __('Causa'),
            'situation' => __('Especificação perante a greve'),
            'cause_note' => __('Descrição da causa'),
        ];
        $changes = [];

        foreach (LessonOutcomeRectification::FIELDS as $field) {
            $from = $rectification->previous[$field] ?? null;
            $to = $rectification->current[$field] ?? null;

            if ($from === $to) {
                continue;
            }

            $changes[] = [
                'field' => $field,
                'label' => $labels[$field],
                'from' => self::valueLabel($field, $from),
                'to' => self::valueLabel($field, $to),
            ];
        }

        if ($rectification->kind === LessonOutcomeRectification::KIND_ANNULMENT) {
            $number = $rectification->previous['lesson_number'] ?? null;
            $changes[] = ['field' => 'lesson_number', 'label' => (string) __('N.º da lição'), 'from' => $number === null ? '—' : (string) $number, 'to' => '—'];

            $summary = $rectification->previous['summary_content'] ?? null;

            if ($includeSummary && is_string($summary) && $summary !== '') {
                $changes[] = ['field' => 'summary', 'label' => (string) __('Sumário removido'), 'from' => $summary, 'to' => '—'];
            }
        }

        return $changes;
    }

    private static function valueLabel(string $field, ?string $value): string
    {
        if ($value === null) {
            return '—';
        }

        return match ($field) {
            'outcome' => LessonOutcome::tryFrom($value)?->label() ?? $value,
            'reason' => TeacherAbsenceReason::tryFrom($value)?->label() ?? $value,
            'cause' => LessonNotHeldCause::tryFrom($value)?->label() ?? $value,
            'situation' => TeacherStrikeSituation::tryFrom($value)?->label() ?? $value,
            default => $value,
        };
    }
}
