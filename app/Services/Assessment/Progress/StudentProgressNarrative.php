<?php

namespace App\Services\Assessment\Progress;

use App\Services\Reporting\Narrative\Phrase;

/**
 * The paragraph at the top, assembled from facts already on the page (§38).
 *
 * DETERMINISTIC, AND DELIBERATELY SO. There is no AI anywhere near this view
 * (§82): the same figures always produce the same sentence, the sentence can be
 * read against the numbers beside it, and nothing in it depends on a provider
 * being configured. It is a summary of the page, not an opinion about the
 * student.
 *
 * IT REUSES THE REPORTS' OWN VOICE. `Phrase` is where pt-PT number agreement,
 * percentages and sentence assembly already live, and where the whole narrative
 * layer was revised for register. A second set of sentence-building rules would
 * drift from it by the second commit.
 *
 * WHAT IT WILL NOT SAY. No cause, no explanation, no prognosis, no
 * characterisation. «O resultado mais baixo regista-se em Gramática» is a
 * reading of a table; «tem dificuldades em Gramática» is a pedagogical
 * judgement, and this application does not make them on a teacher's behalf
 * (§23, §39). Nor does it relate an intervention to a result: sharing a
 * timeline is not causation, and no sentence here puts the two in one clause
 * (§35).
 *
 * SILENCE IS AN ANSWER. A student with no result gets a sentence saying so, and
 * one with nothing at all gets nothing rather than a paragraph of hedges (§50).
 */
class StudentProgressNarrative
{
    /**
     * @param  array<string, mixed>  $progress
     */
    public function for(array $progress): ?string
    {
        $sentences = array_filter([
            $this->current($progress),
            $this->movement($progress),
            $this->domains($progress),
            $this->coverage($progress),
        ]);

        return $sentences === [] ? null : Phrase::paragraph(array_values($sentences));
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    protected function current(array $progress): ?string
    {
        $value = Phrase::percentage($progress['headline']['value'] ?? null);
        $label = (string) ($progress['reading']['label'] ?? 'Média Ponderada');

        if ($value === null) {
            // NOT A LOW RESULT. An absence of evidence is not a bad figure, and
            // the sentence that says so is the same one the reports use (§24).
            return 'Não existem resultados apurados para este aluno no período analisado.';
        }

        return Phrase::sentence('O aluno apresenta atualmente uma', $label, 'de', $value);
    }

    /**
     * The movement, named for the reading it belongs to.
     *
     * Two movements exist and they answer different questions — the period
     * against the period before, and the reading against the reading before.
     * Whichever the teacher is looking at is the one described, and the sentence
     * says which moment it is relative to rather than leaving «subiu» floating.
     *
     * NAMED BY LABEL ONLY, NEVER BY DATE. The two periods being compared are
     * named once, by label — never with their end date folded into the same
     * clause. The date lives exactly once on the page, inside "Base da
     * comparação" (Show.vue); repeating it here as well as there was the
     * exact double-naming this sentence used to do (§3 of the panel review).
     *
     * @param  array<string, mixed>  $progress
     */
    protected function movement(array $progress): ?string
    {
        $continuous = $progress['reading']['kind'] === 'accumulated';
        $movement = $continuous
            ? ($progress['headline']['continuous_evolution'] ?? null)
            : ($progress['headline']['evolution'] ?? null);

        if (! is_array($movement) || ! isset($movement['direction'], $movement['points'])) {
            return null;
        }

        $comparison = $progress['sinceLast'] ?? null;

        if (! is_array($comparison)
            || ! isset($comparison['from_label'], $comparison['to_label'])
            || ! is_string($comparison['from'] ?? null)
            || ! is_string($comparison['to'] ?? null)) {
            return null;
        }

        $points = ltrim((string) $movement['points'], '-');
        $formatted = Phrase::number($points);
        $from = Phrase::percentage($comparison['from']);
        $to = Phrase::percentage($comparison['to']);

        if ($formatted === null || $from === null || $to === null) {
            return null;
        }

        $fromLabel = (string) $comparison['from_label'];
        $toLabel = (string) $comparison['to_label'];
        // NEVER THE BARE WORD "resultado" WHEN A NAMED READING APPLIES. The
        // label BuildStudentProgress already resolved — "Média Ponderada" or
        // "Média Ponderada Acumulada" — is the metric actually shown above
        // this sentence, lowercased only for it to read as flowing prose
        // (§7 of the panel review). "resultado" survives solely as the last
        // resort when no label reached this payload at all.
        $reading = mb_strtolower((string) ($progress['reading']['label'] ?? 'resultado'));

        if ($movement['direction'] === 'flat') {
            return "Do {$fromLabel} para o {$toLabel}, a {$reading} passou de {$from} para {$to} — sem variação ({$formatted} p.p.).";
        }

        $direction = $movement['direction'] === 'up' ? 'subida' : 'descida';

        return "Do {$fromLabel} para o {$toLabel}, a {$reading} passou de {$from} para {$to} — {$direction} de {$formatted} p.p.";
    }

    /**
     * Where the highest and the lowest figures are.
     *
     * ONLY WHEN THERE ARE TWO DIFFERENT DOMAINS TO NAME. A tie has no single
     * answer, one domain is not a comparison, and the same domain being both
     * would be a sentence about nothing.
     *
     * @param  array<string, mixed>  $progress
     */
    protected function domains(array $progress): ?string
    {
        $highest = $progress['domains']['highlights']['highest'] ?? null;
        $lowest = $progress['domains']['highlights']['lowest'] ?? null;

        if (! is_array($highest) || ! is_array($lowest)) {
            return null;
        }

        if ($highest['domain_id'] === $lowest['domain_id']) {
            return null;
        }

        $highestValue = Phrase::percentage($highest['value'] ?? null);
        $lowestValue = Phrase::percentage($lowest['value'] ?? null);

        if ($highestValue === null || $lowestValue === null) {
            return null;
        }

        return Phrase::sentence(
            'O resultado mais elevado regista-se em',
            (string) $highest['name'],
            'com',
            $highestValue,
            'e o mais baixo em',
            (string) $lowest['name'],
            'com',
            $lowestValue,
        );
    }

    /**
     * The reservation, when there is one.
     *
     * A figure resting on part of the evidence says so, in the same words the
     * reports use — the reader is told what the number is made of rather than
     * left to assume it is complete (§24, §51).
     *
     * @param  array<string, mixed>  $progress
     */
    protected function coverage(array $progress): ?string
    {
        return ($progress['headline']['coverage'] ?? null) === 'partial'
            ? 'Este resultado assenta em parte dos instrumentos previstos, pelo que deve ser lido com essa reserva.'
            : null;
    }
}
