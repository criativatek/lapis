<?php

namespace App\Services\Import\Timetable;

use App\Models\RecurringLessonSlot;

/**
 * Whether a block from the file is new, already there, or in the way.
 *
 * NEW LOGIC, NOT A WRAPPER: nothing in this application prevented two
 * overlapping recurring slots before this import existed — not a unique index,
 * not a validation rule, not a controller check — so this is the only place the
 * question is asked, and both the preview and the confirmation ask it through
 * here so that the two can never disagree about the answer.
 *
 * The confirmation asks AGAIN, against freshly loaded slots, rather than
 * trusting what the preview decided minutes earlier: a teacher who added a slot
 * by hand in another tab in the meantime must not end up with a duplicate.
 *
 * IDENTITY IS DAY + START + END, and deliberately nothing more. The optional
 * validity window (`starts_on`/`ends_on`) is not part of it: re-importing the
 * same file must not produce a second copy of a block merely because the second
 * import was given different dates. Automatic merging or replacing of an
 * overlapping slot is out of scope — a conflict is reported and left for the
 * teacher to resolve on the ordinary schedule screen.
 */
class DetectSlotConflicts
{
    public const STATUS_NEW = 'new';

    public const STATUS_EXISTS = 'exists';

    public const STATUS_CONFLICT = 'conflict';

    /**
     * DOIS GRUPOS DIFERENTES À MESMA HORA NÃO SÃO UM CONFLITO — é o
     * desdobramento a funcionar.
     *
     * A regra canónica, e as quatro combinações que ela decide:
     *
     *   turma inteira + turma inteira  → conflito (o mesmo de sempre)
     *   turma inteira + T1             → conflito: os alunos de T1 estariam
     *                                    nos dois sítios ao mesmo tempo
     *   T1 + T1                        → conflito
     *   T1 + T2                        → PERMITIDO: é literalmente para isto
     *                                    que os grupos existem
     *
     * Dito de uma vez: só há conflito quando os dois tempos partilham alunos, e
     * dois grupos distintos da mesma turma nunca partilham nenhum — é o que a
     * não-sobreposição das pertenças garante (§ ClassGroupMembershipRules).
     *
     * A IDENTIDADE PASSA A INCLUIR O GRUPO, e só ela. «Já lá está» é agora
     * mesmo dia, mesma hora E mesmo grupo: sem isso, reimportar um ficheiro
     * depois de o professor ter marcado à mão aquele bloco como T1 diria «já
     * existe» a um bloco de turma inteira que não existe. A janela de vigência
     * continua de fora, como sempre esteve.
     *
     * PARA A IMPORTAÇÃO NADA MUDA. O parâmetro é opcional e os dois chamadores
     * — a pré-visualização e a confirmação — não o passam, porque o parser da
     * v1 ainda não sabe ler grupos: NULL contra NULL dá exatamente as mesmas
     * respostas que dava antes (§20 do briefing).
     *
     * @param  iterable<RecurringLessonSlot>  $existingSlots  every slot of the turma this block would join
     * @param  int|null  $classGroupId  o grupo deste bloco; NULL = turma inteira
     */
    public function status(
        iterable $existingSlots,
        int $dayOfWeek,
        string $startsAt,
        string $endsAt,
        ?int $classGroupId = null,
    ): string {
        $start = $this->minutes($startsAt);
        $end = $this->minutes($endsAt);
        $conflict = false;

        foreach ($existingSlots as $slot) {
            if ($slot->day_of_week !== $dayOfWeek) {
                continue;
            }

            $slotStart = $this->minutes($slot->starts_at);
            $slotEnd = $this->minutes($slot->ends_at);
            $sameGroup = $slot->class_group_id === $classGroupId;

            // Exactly this block already exists. Returned immediately even
            // though it also "overlaps": already-there is the more specific and
            // the more useful answer, and it is what makes a second import of
            // the same file create nothing at all.
            if ($sameGroup && $slotStart === $start && $slotEnd === $end) {
                return self::STATUS_EXISTS;
            }

            // Dois grupos distintos, ambos não nulos: não partilham alunos, e
            // por isso podem ocupar a mesma hora. Qualquer outro par — os dois
            // nulos, ou um nulo e outro não — partilha gente.
            $disjointGroups = $slot->class_group_id !== null
                && $classGroupId !== null
                && ! $sameGroup;

            if (! $disjointGroups && $slotStart < $end && $start < $slotEnd) {
                $conflict = true;
            }
        }

        return $conflict ? self::STATUS_CONFLICT : self::STATUS_NEW;
    }

    /**
     * «09:30» and «09:30:00» are the same instant.
     *
     * The column is a `time`, so Eloquent hands back «HH:MM:SS»; the file and
     * the manual form both speak «HH:MM». Comparing the strings directly would
     * make every single existing slot look different from every imported one.
     */
    protected function minutes(string $time): int
    {
        $parts = explode(':', trim($time));

        return ((int) $parts[0]) * 60 + (isset($parts[1]) ? (int) $parts[1] : 0);
    }
}
