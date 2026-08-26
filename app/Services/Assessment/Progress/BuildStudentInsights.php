<?php

namespace App\Services\Assessment\Progress;

use App\Domain\Assessment\Bc;
use App\Models\DisciplinarySeverity;
use App\Models\EvidenceKind;
use App\Models\HomeworkStatus;
use App\Services\Reporting\Narrative\Phrase;

/**
 * Pro: the interpretive layer, built ON TOP of the payload
 * `BuildStudentProgress` and the Base factual services already assembled.
 *
 * NEVER A SECOND ENGINE. This class takes three already-built arrays — the
 * progress payload, the Base factual alerts and the Base strengths — and adds
 * reading. It queries nothing: every sentence below traces back to a figure,
 * a count or a direction one of those three already computed. Where a
 * genuinely new comparison is needed (the previous period's alert counts, for
 * «o que mudou»), the controller resolves the extra period and hands this
 * class the same kind of already-built array, never a model.
 *
 * A NEUTRAL STATE IS ALWAYS AVAILABLE. Every dimension of Estado 360º and
 * every interpretive method below has an explicit "not enough evidence"
 * answer, and returns it rather than forcing a reading a small number of
 * points cannot support (§1).
 *
 * WHAT NEVER APPEARS HERE: a predicted grade or percentage, a claim that an
 * intervention caused a result to move, or a characterisation of the
 * student's motivation, self-esteem or personality. "Foi observada evolução
 * positiva após o início da estratégia" is the required register throughout
 * — correlation in time, stated once, never dressed up as causation.
 */
class BuildStudentInsights
{
    /**
     * @param  array<string, mixed>  $progress  BuildStudentProgress::for()
     * @param  list<array{key: string, sentence: string, count: int}>  $currentAlerts  BuildStudentFactualAlerts::for(), current period
     * @param  list<array{key: string, sentence: string, count: int}>|null  $previousAlerts  null when no previous period exists
     * @param  list<array{key: string, sentence: string}>  $strengths  BuildStudentStrengths::for()
     * @return array<string, mixed>
     */
    public function for(array $progress, array $currentAlerts, ?array $previousAlerts, array $strengths): array
    {
        $performance = $this->performance($progress);
        $trend = $this->trend($progress);
        $regularity = $this->regularity($progress);
        $engagement = $this->academicEngagement($progress);
        $attitude = $this->attitude($progress, $previousAlerts);
        $tracking = $this->tracking($progress);
        $strengthsDimension = $this->strengthsDimension($progress, $strengths);
        $margin = $this->progressionMargin($progress, $trend, $regularity, $performance);

        $evolutionAfterStrategy = $this->evolutionAfterStrategy($progress);
        $whatChanged = $this->whatChanged($progress);
        $analyticalAlerts = $this->analyticalAlerts($progress, $currentAlerts, $previousAlerts, $trend, $regularity);
        $positiveSignals = $this->positiveSignals($progress, $trend, $regularity, $performance, $currentAlerts, $previousAlerts, $evolutionAfterStrategy);
        $potentialities = $this->potentialities($progress, $margin);

        return [
            'estado360' => [
                'dimensions' => [
                    $performance, $trend, $regularity, $engagement,
                    $attitude, $tracking, $strengthsDimension, $margin,
                ],
            ],
            'analyticalAlerts' => $analyticalAlerts,
            'positiveSignals' => $positiveSignals,
            'potentialities' => $potentialities,
            'whatChanged' => $whatChanged,
            'evolutionAfterStrategy' => $evolutionAfterStrategy,
            'conversationPrep' => $this->conversationPrep(
                $progress, $currentAlerts, $strengths, $analyticalAlerts,
                $positiveSignals, $potentialities, $whatChanged, $evolutionAfterStrategy,
            ),
        ];
    }

    // -------------------------------------------------------- estado 360º

    /**
     * @return array{key: string, label: string, state: string, state_label: string, detail: string}
     */
    protected function dimension(string $key, string $label, string $state, string $stateLabel, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'state' => $state, 'state_label' => $stateLabel, 'detail' => $detail];
    }

    /**
     * Rendimento — read against the class, never against an invented cutoff.
     * The comparison is `classComparison`, already built by
     * BuildClassStatistics; only its SIGN is read here, never a magnitude
     * threshold nobody chose.
     *
     * @param  array<string, mixed>  $progress
     * @return array{key: string, label: string, state: string, state_label: string, detail: string}
     */
    protected function performance(array $progress): array
    {
        $comparison = $progress['classComparison'] ?? null;
        $coverage = $progress['headline']['coverage'] ?? 'none';

        if ($coverage === 'none' || $comparison === null) {
            return $this->dimension('rendimento', 'Rendimento', 'sem_evidencia', 'Sem elementos suficientes',
                'Ainda não existem elementos avaliados suficientes para comparar com a turma.');
        }

        $sign = Bc::compare(Bc::of($comparison['difference']), '0');

        return match (true) {
            $sign > 0 => $this->dimension('rendimento', 'Rendimento', 'acima_da_turma', 'Acima da turma',
                'O resultado do aluno está acima da média da turma no momento analisado.'),
            $sign < 0 => $this->dimension('rendimento', 'Rendimento', 'abaixo_da_turma', 'Abaixo da turma',
                'O resultado do aluno está abaixo da média da turma no momento analisado.'),
            default => $this->dimension('rendimento', 'Rendimento', 'em_linha_com_a_turma', 'Em linha com a turma',
                'O resultado do aluno está em linha com a média da turma no momento analisado.'),
        };
    }

    /**
     * Tendência — the same movement StudentProgressNarrative already reads,
     * for the same reading (period or acumulada), never recalculated.
     *
     * @param  array<string, mixed>  $progress
     * @return array{key: string, label: string, state: string, state_label: string, detail: string}
     */
    protected function trend(array $progress): array
    {
        $continuous = ($progress['reading']['kind'] ?? 'period') === 'accumulated';
        $movement = $continuous
            ? ($progress['headline']['continuous_evolution'] ?? null)
            : ($progress['headline']['evolution'] ?? null);

        if (! is_array($movement) || ! isset($movement['direction'])) {
            return $this->dimension('tendencia', 'Tendência', 'sem_evidencia', 'Sem evidência suficiente',
                'Ainda não existem dois momentos comparáveis para descrever uma tendência.');
        }

        $detail = $this->movementDetail($progress, $movement);

        if ($detail === null) {
            return $this->dimension('tendencia', 'Tendência', 'sem_evidencia', 'Sem evidência suficiente',
                'Ainda não existem dois momentos comparáveis para descrever uma tendência.');
        }

        return match ($movement['direction']) {
            'up' => $this->dimension('tendencia', 'Tendência', 'subida', 'Subida',
                $detail),
            'down' => $this->dimension('tendencia', 'Tendência', 'descida', 'Descida',
                $detail),
            default => $this->dimension('tendencia', 'Tendência', 'estavel', 'Estável',
                $detail),
        };
    }

    /**
     * NAMED BY LABEL ONLY, NEVER BY DATE — the two periods are named once,
     * by label; the date lives exactly once on the page, inside "Base da
     * comparação" (Show.vue), never folded into this clause as well (§3 of
     * the panel review).
     *
     * NEVER THE BARE WORD "resultado" WHEN A NAMED READING APPLIES — the
     * same reading label the headline above it already shows ("Média
     * Ponderada" / "Média Ponderada Acumulada"), lowercased only so it reads
     * as flowing prose (§7 of the panel review).
     *
     * @param  array<string, mixed>  $progress
     * @param  array<string, mixed>  $movement
     */
    protected function movementDetail(array $progress, array $movement): ?string
    {
        $comparison = $progress['sinceLast'] ?? null;

        if (! is_array($comparison)
            || ! is_string($comparison['from'] ?? null)
            || ! is_string($comparison['to'] ?? null)
            || ! isset($comparison['from_label'], $comparison['to_label'], $movement['points'])) {
            return null;
        }

        $from = Phrase::percentage($comparison['from']);
        $to = Phrase::percentage($comparison['to']);
        $points = Phrase::number(ltrim((string) $movement['points'], '-'));

        if ($from === null || $to === null || $points === null) {
            return null;
        }

        $fromLabel = (string) $comparison['from_label'];
        $toLabel = (string) $comparison['to_label'];
        $reading = mb_strtolower((string) ($progress['reading']['label'] ?? 'resultado'));

        if (($movement['direction'] ?? 'flat') === 'flat') {
            return "Do {$fromLabel} para o {$toLabel}, a {$reading} passou de {$from} para {$to} — sem variação ({$points} p.p.).";
        }

        $direction = $movement['direction'] === 'up' ? 'subida' : 'descida';

        return "Do {$fromLabel} para o {$toLabel}, a {$reading} passou de {$from} para {$to} — {$direction} de {$points} p.p.";
    }

    /**
     * Regularidade — the direction between each pair of the three most
     * recent moments with a value, compared pairwise with the SAME numbers
     * BuildStudentProgress already put in `moments`. No new average is
     * computed anywhere in this method.
     *
     * @param  array<string, mixed>  $progress
     * @return array{key: string, label: string, state: string, state_label: string, detail: string}
     */
    protected function regularity(array $progress): array
    {
        $withValue = array_values(array_filter(
            (array) ($progress['moments'] ?? []),
            fn (array $moment): bool => $moment['value'] !== null,
        ));

        $recent = array_slice($withValue, -3);

        if (count($recent) < 3) {
            return $this->dimension('regularidade', 'Regularidade', 'sem_evidencia', 'Sem evidência suficiente',
                'Ainda não existem três momentos comparáveis para descrever regularidade.');
        }

        $directions = [];

        for ($i = 1; $i < count($recent); $i++) {
            $directions[] = Bc::compare(Bc::of($recent[$i]['value']), Bc::of($recent[$i - 1]['value'])) <=> 0;
        }

        $hasUp = in_array(1, $directions, true);
        $hasDown = in_array(-1, $directions, true);

        return match (true) {
            $hasUp && ! $hasDown => $this->dimension('regularidade', 'Regularidade', 'consistente_a_subir', 'Subida consistente',
                'Observa-se uma subida consistente nos três elementos mais recentes.'),
            $hasDown && ! $hasUp => $this->dimension('regularidade', 'Regularidade', 'consistente_a_descer', 'Descida consistente',
                'Observa-se uma descida consistente nos três elementos mais recentes.'),
            default => $this->dimension('regularidade', 'Regularidade', 'variavel', 'Variável',
                'Não se observa uma direção consistente nos elementos mais recentes.'),
        };
    }

    /**
     * Empenho académico — read from the labels already on `records.rows`
     * (majority rule over Trabalho de casa records only; a class that never
     * logs homework returns the neutral state rather than a false reading).
     *
     * @param  array<string, mixed>  $progress
     * @return array{key: string, label: string, state: string, state_label: string, detail: string}
     */
    protected function academicEngagement(array $progress): array
    {
        $homework = array_values(array_filter(
            $this->recordsInSelectedPeriod($progress),
            fn (array $row): bool => $row['kind'] === EvidenceKind::Homework->value,
        ));

        if ($homework === []) {
            return $this->dimension('empenho_academico', 'Empenho académico', 'sem_evidencia', 'Sem evidência suficiente',
                'Ainda não existem registos de trabalho de casa para este aluno.');
        }

        $done = count(array_filter($homework, fn (array $row): bool => $row['homework_status'] === HomeworkStatus::Done->label()));
        $partial = count(array_filter($homework, fn (array $row): bool => $row['homework_status'] === HomeworkStatus::PartiallyDone->label()));
        $notDone = count(array_filter($homework, fn (array $row): bool => $row['homework_status'] === HomeworkStatus::NotDone->label()));
        $count = count($homework);
        $counts = $this->homeworkCounts($count, $done, $partial, $notDone);

        if ($count <= 2) {
            return $this->dimension(
                'empenho_academico', 'Empenho académico', 'sem_evidencia', 'Sem evidência suficiente',
                $counts.' Não há ainda evidência suficiente para identificar uma tendência consistente.',
            );
        }

        return match (true) {
            $notDone > $done + $partial => $this->dimension(
                'empenho_academico', 'Empenho académico', 'a_reforcar', 'A reforçar',
                $counts.' Predominam os registos de trabalho de casa não realizado.',
            ),
            $done > $partial + $notDone => $this->dimension(
                'empenho_academico', 'Empenho académico', 'consistente', 'Consistente',
                $counts.' Predominam os registos de trabalho de casa realizado.',
            ),
            default => $this->dimension(
                'empenho_academico', 'Empenho académico', 'sem_tendencia', 'Sem tendência dominante',
                $counts.' Não se observa ainda uma tendência dominante.',
            ),
        };
    }

    protected function homeworkCounts(int $total, int $done, int $partial, int $notDone): string
    {
        $parts = [];

        if ($done > 0) {
            $parts[] = $this->plural($done, ':n realizado', ':n realizados');
        }

        if ($partial > 0) {
            $parts[] = $this->plural($partial, ':n parcialmente realizado', ':n parcialmente realizados');
        }

        if ($notDone > 0) {
            $parts[] = $this->plural($notDone, ':n não realizado', ':n não realizados');
        }

        $prefix = $this->plural($total, 'Existe :n registo de trabalho de casa', 'Existem :n registos de trabalho de casa');

        return $prefix.($parts === [] ? '.' : ': '.$this->joinPortuguese($parts).'.');
    }

    /**
     * Atitudes/comportamento — read from Incident/Comportamento meritório
     * records already on `records.rows`.
     *
     * @param  array<string, mixed>  $progress
     * @param  list<array{key: string, sentence: string, count: int}>|null  $previousAlerts
     * @return array{key: string, label: string, state: string, state_label: string, detail: string}
     */
    protected function attitude(array $progress, ?array $previousAlerts): array
    {
        $rows = $this->recordsInSelectedPeriod($progress);
        $incidents = array_values(array_filter($rows, fn (array $row): bool => $row['kind'] === EvidenceKind::Incident->value));
        $positive = array_values(array_filter($rows, fn (array $row): bool => $row['kind'] === EvidenceKind::PositiveBehaviour->value));

        if ($incidents === [] && $positive === []) {
            return $this->dimension('atitudes_comportamento', 'Atitudes e comportamento', 'sem_registos', 'Sem registos suficientes',
                'Ainda não existem registos de comportamento para este aluno.');
        }

        $severeLabels = [
            DisciplinarySeverity::Grade5->label(),
            DisciplinarySeverity::Grade6->label(),
        ];
        $severe = array_filter($incidents, fn (array $row): bool => in_array($row['severity'], $severeLabels, true));

        if ($incidents !== []) {
            $detail = $this->incidentDetail($incidents);
            $previousCount = $previousAlerts === null ? null : $this->alertCount($previousAlerts, 'disciplinary_incidents');

            if (count($incidents) > 1 && $previousCount !== null && count($incidents) > $previousCount) {
                $window = $this->comparisonWindow($progress);
                $detail .= $window === null
                    ? ' A frequência aumentou de '.$previousCount.' para '.count($incidents).' ocorrências.'
                    : ' Entre o '.$window['from'].' e o '.$window['to'].', a frequência aumentou de '.$previousCount.' para '.count($incidents).' ocorrências.';
            }

            $isolatedNonSevere = count($incidents) === 1 && $severe === [];

            return $this->dimension(
                'atitudes_comportamento',
                'Atitudes e comportamento',
                $isolatedNonSevere ? 'registo_isolado' : ($severe !== [] || count($incidents) > count($positive) ? 'a_acompanhar' : 'estavel'),
                $isolatedNonSevere ? 'Registo isolado' : ($severe !== [] || count($incidents) > count($positive) ? 'A acompanhar' : 'Estável'),
                $detail,
            );
        }

        // Reached only when $incidents is empty — the branch above always
        // returns otherwise — and the guard at the top of this method already
        // ruled out both being empty at once, so $positive is guaranteed
        // non-empty here.
        return $this->dimension(
            'atitudes_comportamento', 'Atitudes e comportamento', 'positivo', 'Positivo',
            $this->plural(count($positive), 'Existe :n registo de comportamento meritório', 'Existem :n registos de comportamento meritório').' no período analisado, sem ocorrências disciplinares.',
        );
    }

    /**
     * @param  list<array<string, mixed>>  $incidents
     */
    protected function incidentDetail(array $incidents): string
    {
        if (count($incidents) === 1) {
            $label = preg_replace('/\s*\(G[2-6]\)$/u', '', (string) ($incidents[0]['severity'] ?? ''));

            return '1 ocorrência disciplinar registada no período'.($label === '' ? '.' : ': '.$label.'.');
        }

        $light = [DisciplinarySeverity::Grade2->label(), DisciplinarySeverity::Grade3->label()];
        $moderate = [DisciplinarySeverity::Grade4->label()];
        $grave = [DisciplinarySeverity::Grade5->label(), DisciplinarySeverity::Grade6->label()];
        $groups = [
            'ligeira' => count(array_filter($incidents, fn (array $row): bool => in_array($row['severity'], $light, true))),
            'moderada' => count(array_filter($incidents, fn (array $row): bool => in_array($row['severity'], $moderate, true))),
            'grave' => count(array_filter($incidents, fn (array $row): bool => in_array($row['severity'], $grave, true))),
        ];
        $known = array_sum($groups);
        $parts = [];

        foreach ($groups as $label => $count) {
            if ($count > 0) {
                $parts[] = $count.' '.($count === 1 ? $label : match ($label) {
                    'ligeira' => 'ligeiras',
                    'moderada' => 'moderadas',
                    default => 'graves',
                });
            }
        }

        if ($known < count($incidents)) {
            $unknown = count($incidents) - $known;
            $parts[] = $this->plural($unknown, ':n sem grau indicado', ':n sem grau indicado');
        }

        return count($incidents).' ocorrências disciplinares registadas no período: '.$this->joinPortuguese($parts).'.';
    }

    /**
     * @param  list<array{key: string, count: int}>  $alerts
     */
    protected function alertCount(array $alerts, string $key): int
    {
        foreach ($alerts as $alert) {
            if ($alert['key'] === $key) {
                return $alert['count'];
            }
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $progress
     * @return list<array<string, mixed>>
     */
    protected function recordsInSelectedPeriod(array $progress): array
    {
        $rows = array_values((array) ($progress['records']['rows'] ?? []));
        $startsOn = $progress['selectedPeriod']['starts_on'] ?? null;
        $endsOn = $progress['selectedPeriod']['ends_on'] ?? null;

        if (! is_string($startsOn) || ! is_string($endsOn)) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            fn (array $row): bool => ($row['occurred_at'] ?? '') >= $startsOn && ($row['occurred_at'] ?? '') <= $endsOn,
        ));
    }

    /**
     * @param  list<string>  $parts
     */
    protected function joinPortuguese(array $parts): string
    {
        if (count($parts) <= 1) {
            return $parts[0] ?? '';
        }

        $last = array_pop($parts);

        return implode(', ', $parts).' e '.$last;
    }

    /**
     * Acompanhamento — read straight from `interventions.total` /
     * `.needing_review`, both already computed by BuildStudentProgress.
     *
     * @param  array<string, mixed>  $progress
     * @return array{key: string, label: string, state: string, state_label: string, detail: string}
     */
    protected function tracking(array $progress): array
    {
        $total = (int) ($progress['interventions']['total'] ?? 0);
        $needingReview = (int) ($progress['interventions']['needing_review'] ?? 0);

        return match (true) {
            $total === 0 => $this->dimension('acompanhamento', 'Acompanhamento', 'sem_intervencao', 'Sem intervenção registada',
                'Não existem intervenções registadas para este aluno.'),
            $needingReview > 0 => $this->dimension('acompanhamento', 'Acompanhamento', 'revisao_pendente', 'Revisão pendente',
                'Existe pelo menos uma intervenção com revisão pendente.'),
            default => $this->dimension('acompanhamento', 'Acompanhamento', 'em_curso', 'Em curso',
                'Existem intervenções registadas, sem revisões pendentes neste momento.'),
        };
    }

    /**
     * Pontos fortes — whether Base already found something to name. The
     * dimension itself adds no new fact; it says whether the section below
     * it has content.
     *
     * @param  array<string, mixed>  $progress
     * @param  list<array{key: string, sentence: string}>  $strengths
     * @return array{key: string, label: string, state: string, state_label: string, detail: string}
     */
    protected function strengthsDimension(array $progress, array $strengths): array
    {
        if ($strengths === []) {
            return $this->dimension('pontos_fortes', 'Pontos fortes', 'sem_elementos', 'Sem elementos suficientes',
                'Ainda não existem elementos suficientes para identificar pontos fortes.');
        }

        $highest = $progress['domains']['highlights']['highest'] ?? null;

        if (is_array($highest)) {
            $value = Phrase::percentage($highest['value'] ?? null);
            $row = collect((array) ($progress['domains']['rows'] ?? []))->firstWhere('domain_id', $highest['domain_id']);
            $mention = is_array($row) ? ($row['mention']['label'] ?? null) : null;

            if ($value !== null) {
                return $this->dimension(
                    'pontos_fortes', 'Pontos fortes', 'identificados', 'Identificados',
                    $highest['name'].' — ponto forte atual — '.$value.($mention === null ? '' : ' · '.$mention).'.',
                );
            }
        }

        return $this->dimension('pontos_fortes', 'Pontos fortes', 'identificados', 'Identificados',
            (string) $strengths[0]['sentence']);
    }

    /**
     * Margem de progressão — cautious, and never a predicted grade or
     * percentage. Built only from `regularidade` and `rendimento`, both
     * already resolved above from figures BuildStudentProgress computed.
     *
     * @param  array<string, mixed>  $progress
     * @param  array{state: string}  $trend
     * @param  array{state: string}  $regularity
     * @param  array{state: string}  $performance
     * @return array{key: string, label: string, state: string, state_label: string, detail: string}
     */
    protected function progressionMargin(array $progress, array $trend, array $regularity, array $performance): array
    {
        if (($progress['headline']['coverage'] ?? 'none') === 'none') {
            return $this->dimension('margem_progressao', 'Margem de progressão', 'sem_elementos', 'Sem elementos suficientes',
                'Ainda não existem elementos avaliados suficientes para descrever margem de progressão.');
        }

        if ($regularity['state'] === 'consistente_a_subir') {
            return $this->dimension('margem_progressao', 'Margem de progressão', 'em_progressao', 'Em progressão',
                'O aluno está próximo do patamar seguinte, com uma trajetória de subida consistente. '.
                'Apresenta condições para progredir se consolidar os elementos mais recentes.');
        }

        if ($performance['state'] === 'abaixo_da_turma' || $regularity['state'] === 'consistente_a_descer' || $trend['state'] === 'descida') {
            return $this->dimension('margem_progressao', 'Margem de progressão', 'foco_na_consolidacao', 'Foco na consolidação',
                $this->consolidationDetail($progress));
        }

        return $this->dimension('margem_progressao', 'Margem de progressão', 'consolidado_com_margem', 'Consolidado, com margem',
            'O aluno demonstra desempenho elevado e consistente, com margem para tarefas de maior complexidade.');
    }

    /**
     * The ONE resolution of "the domain needing consolidation" — read once,
     * here, and reused by both `consolidationDetail()` (this dimension's own
     * wording) and `potentialities()`'s `nextStep` (§4 of the panel review,
     * round 2). Two independently-computed notions of "weakest domain" is
     * exactly how the page ended up naming Gramática in one section and
     * saying nothing about it in the other; resolving it once and handing
     * both callers the same array is what keeps that from happening again.
     *
     * Returns null when the data cannot support naming a domain at all —
     * no lowest highlight, or a value `Phrase::percentage()` cannot format
     * — so a caller with no real figure to show never fabricates one.
     *
     * @param  array<string, mixed>  $progress
     * @return array{domain_id: int, name: string, value: string}|null
     */
    protected function consolidationPriority(array $progress): ?array
    {
        $lowest = $progress['domains']['highlights']['lowest'] ?? null;

        if (! is_array($lowest)) {
            return null;
        }

        $value = Phrase::percentage($lowest['value'] ?? null);

        if ($value === null) {
            return null;
        }

        return [
            'domain_id' => (int) $lowest['domain_id'],
            'name' => (string) $lowest['name'],
            'value' => $value,
        ];
    }

    /**
     * Names the concrete domain worth consolidating, when the data supports
     * naming one — the lowest domain, resolved once by
     * `consolidationPriority()`. Never padded with a second, invented
     * domain: when only one is clearly supported, only one is named (§6 of
     * the panel review). Direct and factual, with the real current value —
     * never a euphemism for it (§3 of the panel review, round 2).
     *
     * RECONCILED WITH POTENCIALIDADES. `potentialities()` names `largest_rise`
     * as "em progressão"; if that happens to be the SAME domain this method
     * would otherwise call out as needing consolidation, both facts are
     * stated together rather than left as an unexplained contradiction
     * between the two sections.
     *
     * @param  array<string, mixed>  $progress
     */
    protected function consolidationDetail(array $progress): string
    {
        $generic = 'Face à trajetória atual, o foco está em consolidar a aprendizagem de base antes de avançar para novos patamares.';
        $priority = $this->consolidationPriority($progress);

        if ($priority === null) {
            return $generic;
        }

        $rise = $progress['domains']['highlights']['largest_rise'] ?? null;
        $alsoRising = is_array($rise) && (int) $rise['domain_id'] === $priority['domain_id'];

        if ($alsoRising) {
            return "A prioridade de consolidação é {$priority['name']} ({$priority['value']}), também o domínio com maior subida recente — o foco está em consolidar essa subida antes de avançar para novos patamares.";
        }

        return "A prioridade de consolidação é {$priority['name']}, atualmente o domínio com resultado mais baixo ({$priority['value']}).";
    }

    // ---------------------------------------------------- evolução após estratégia

    /**
     * Evidence before vs. after each intervention's `started_on` — the last
     * period moment before the date against the last one at or after it,
     * both already in `moments`. Correlation in time, stated once, never
     * causation (§35 of BuildStudentProgress).
     *
     * @param  array<string, mixed>  $progress
     * @return list<array{ulid: string, title: string|null, state: string, sentence: string}>
     */
    protected function evolutionAfterStrategy(array $progress): array
    {
        $periodMoments = array_values(array_filter(
            (array) ($progress['moments'] ?? []),
            fn (array $moment): bool => $moment['kind'] === 'period' && $moment['value'] !== null && $moment['date'] !== null,
        ));

        $results = [];

        foreach ((array) ($progress['interventions']['rows'] ?? []) as $row) {
            $startedOn = $row['started_on'] ?? null;

            if ($startedOn === null) {
                continue;
            }

            $before = array_values(array_filter($periodMoments, fn (array $moment): bool => $moment['date'] < $startedOn));
            $after = array_values(array_filter($periodMoments, fn (array $moment): bool => $moment['date'] >= $startedOn));

            if ($before === [] || $after === []) {
                $results[] = [
                    'ulid' => $row['ulid'],
                    'title' => $row['title'],
                    'state' => 'insufficient',
                    'sentence' => 'Ainda não existem evidências suficientes para avaliar a evolução.',
                ];

                continue;
            }

            $comparison = Bc::compare(Bc::of(end($after)['value']), Bc::of(end($before)['value']));

            $results[] = [
                'ulid' => $row['ulid'],
                'title' => $row['title'],
                'state' => $comparison > 0 ? 'positive' : ($comparison < 0 ? 'negative' : 'stable'),
                'sentence' => match (true) {
                    $comparison > 0 => 'Foi observada evolução positiva após o início da estratégia.',
                    $comparison < 0 => 'Não foi observada evolução positiva após o início da estratégia.',
                    default => 'O resultado manteve-se estável após o início da estratégia.',
                },
            ];
        }

        return $results;
    }

    // ------------------------------------------------------------ o que mudou

    /**
     * The Pro "O que mudou?" block: the same `sinceLast` figures Base already
     * shows, plus what the base builder did not already say — a change in
     * how many records exist, a new intervention, a new self-assessment,
     * all bounded to the same window `sinceLast` describes (the last two
     * period moments with a value).
     *
     * @param  array<string, mixed>  $progress
     * @return array{items: list<array{key: string, sentence: string}>, empty_sentence: string|null}
     */
    protected function whatChanged(array $progress): array
    {
        $items = [];
        $sinceLast = $progress['sinceLast'] ?? null;
        $window = $this->comparisonWindow($progress);

        if (is_array($sinceLast) && $sinceLast['from'] !== null && $sinceLast['to'] !== null) {
            $continuous = ($progress['reading']['kind'] ?? 'period') === 'accumulated';
            $movement = $continuous
                ? ($progress['headline']['continuous_evolution'] ?? null)
                : ($progress['headline']['evolution'] ?? null);
            $detail = is_array($movement) ? $this->movementDetail($progress, $movement) : null;

            // The degraded fallback — reached only when the movement figure
            // itself is missing or malformed, even though sinceLast has both
            // values. STILL NEVER RAW DB PRECISION (§1 of the panel review):
            // routed through Phrase::percentage exactly like the primary
            // $detail path already is, and still named by the real reading
            // label rather than a bare "Resultado".
            $fallback = null;

            if ($detail === null) {
                $fromPct = Phrase::percentage($sinceLast['from']);
                $toPct = Phrase::percentage($sinceLast['to']);

                if ($fromPct !== null && $toPct !== null) {
                    $label = (string) ($progress['reading']['label'] ?? 'Resultado');
                    $fallback = "{$label}: {$fromPct} → {$toPct} ({$sinceLast['from_label']} → {$sinceLast['to_label']}).";
                }
            }

            if ($detail !== null || $fallback !== null) {
                $items[] = [
                    'key' => 'result',
                    'sentence' => $detail ?? $fallback,
                ];
            }
        }

        [$fromDate, $toDate] = $this->lastWindow($progress);

        if ($fromDate !== null && $toDate !== null) {
            $newRecords = array_filter(
                (array) ($progress['records']['rows'] ?? []),
                fn (array $row): bool => $row['occurred_at'] > $fromDate && $row['occurred_at'] <= $toDate,
            );

            if ($newRecords !== []) {
                $count = count($newRecords);
                $sentence = $this->plural($count, 'Foi acrescentado :n novo registo', 'Foram acrescentados :n novos registos');
                $items[] = [
                    'key' => 'records',
                    'sentence' => $window === null ? $sentence.'.' : $sentence.' entre o '.$window['from'].' e o '.$window['to'].'.',
                ];
            }

            $newInterventions = array_filter(
                (array) ($progress['interventions']['rows'] ?? []),
                fn (array $row): bool => ($row['started_on'] ?? null) !== null
                    && $row['started_on'] > $fromDate && $row['started_on'] <= $toDate,
            );

            if ($newInterventions !== []) {
                $sentence = $this->plural(
                    count($newInterventions),
                    'Foi iniciada :n nova intervenção',
                    'Foram iniciadas :n novas intervenções',
                );
                $items[] = [
                    'key' => 'new_intervention',
                    'sentence' => $sentence.($window === null ? '.' : ' entre o '.$window['from'].' e o '.$window['to'].'.'),
                ];
            }
        }

        $withValue = array_values(array_filter(
            (array) ($progress['moments'] ?? []),
            fn (array $moment): bool => $moment['kind'] === 'period' && $moment['value'] !== null,
        ));
        $recent = array_slice($withValue, -2);

        if (count($recent) === 2 && ($recent[1]['self_assessment'] ?? null) !== null && ($recent[0]['self_assessment'] ?? null) === null) {
            $items[] = [
                'key' => 'self_assessment',
                'sentence' => $window === null
                    ? 'Foi submetida uma nova autoavaliação.'
                    : 'Foi submetida uma nova autoavaliação entre o '.$window['from'].' e o '.$window['to'].'.',
            ];
        }

        return [
            'items' => $items,
            'empty_sentence' => $items === [] ? 'Não existem alterações relevantes no período analisado.' : null,
        ];
    }

    /**
     * The dates of the last two period moments with a value — the same pair
     * `sinceLast` is built from, read back out of `moments` for the record and
     * intervention interval itself.
     *
     * @param  array<string, mixed>  $progress
     * @return array{0: string|null, 1: string|null}
     */
    protected function lastWindow(array $progress): array
    {
        $withValue = array_values(array_filter(
            (array) ($progress['moments'] ?? []),
            fn (array $moment): bool => $moment['kind'] === 'period' && $moment['value'] !== null && $moment['date'] !== null,
        ));

        $recent = array_slice($withValue, -2);

        if (count($recent) < 2) {
            return [null, null];
        }

        return [$recent[0]['date'], $recent[1]['date']];
    }

    /**
     * The two periods a comparison sentence names, BY LABEL ONLY — never
     * with the date folded in. The date lives exactly once on the page,
     * inside "Base da comparação" (Show.vue) (§3 of the panel review).
     *
     * @param  array<string, mixed>  $progress
     * @return array{from: string, to: string}|null
     */
    protected function comparisonWindow(array $progress): ?array
    {
        $comparison = $progress['sinceLast'] ?? null;

        if (! is_array($comparison) || ! isset($comparison['from_label'], $comparison['to_label'])) {
            return null;
        }

        return [
            'from' => (string) $comparison['from_label'],
            'to' => (string) $comparison['to_label'],
        ];
    }

    // -------------------------------------------------------- alertas analíticos

    /**
     * The interpretive counterpart to the Base factual alerts: the same
     * facts, with the self-assessment/evidence discrepancy and a
     * period-over-period reading added on top.
     *
     * @param  array<string, mixed>  $progress
     * @param  list<array{key: string, sentence: string, count: int}>  $currentAlerts
     * @param  list<array{key: string, sentence: string, count: int}>|null  $previousAlerts
     * @param  array{state: string}  $trend
     * @param  array{state: string}  $regularity
     * @return list<array{key: string, sentence: string}>
     */
    protected function analyticalAlerts(array $progress, array $currentAlerts, ?array $previousAlerts, array $trend, array $regularity): array
    {
        $alerts = [];

        foreach ((array) ($progress['selfAssessments'] ?? []) as $row) {
            $comparison = $row['comparison'] ?? null;

            if (! is_array($comparison) || $comparison['direction'] === 'same') {
                continue;
            }

            $alerts[] = [
                'key' => 'self_assessment_discrepancy_'.$row['period_id'],
                // Neutral, factual, and only ever a direction — never a
                // diagnosis (§28 of BuildStudentProgress: "desmotivado" is a
                // word this module does not use).
                'sentence' => $comparison['direction'] === 'below'
                    ? 'A autoavaliação do aluno no '.$row['period_label'].' situa-se abaixo da classificação atribuída.'
                    : 'A autoavaliação do aluno no '.$row['period_label'].' situa-se acima da classificação atribuída.',
            ];
        }

        if ($trend['state'] === 'descida' && $regularity['state'] === 'consistente_a_descer') {
            $alerts[] = ['key' => 'consistent_decline', 'sentence' => 'Observa-se uma descida consistente nos elementos mais recentes.'];
        }

        $negativeKeys = ['disciplinary_incidents', 'difficulty_records', 'unresolved_homework'];
        $currentTotal = $this->sumCounts($currentAlerts, $negativeKeys);
        $previousTotal = $previousAlerts === null ? 0 : $this->sumCounts($previousAlerts, $negativeKeys);

        if ($previousAlerts !== null && $currentTotal > $previousTotal) {
            $window = $this->comparisonWindow($progress);
            $alerts[] = [
                'key' => 'negative_records_increase',
                'sentence' => $window === null
                    ? "Os registos que requerem atenção aumentaram de {$previousTotal} para {$currentTotal}."
                    : "Entre o {$window['from']} e o {$window['to']}, os registos que requerem atenção aumentaram de {$previousTotal} para {$currentTotal}.",
            ];
        }

        return $alerts;
    }

    /**
     * @param  list<array{key: string, count: int}>  $alerts
     * @param  list<string>  $keys
     */
    protected function sumCounts(array $alerts, array $keys): int
    {
        $total = 0;

        foreach ($alerts as $alert) {
            if (in_array($alert['key'], $keys, true)) {
                $total += (int) $alert['count'];
            }
        }

        return $total;
    }

    // -------------------------------------------------------- sinais positivos

    /**
     * @param  array<string, mixed>  $progress
     * @param  array{state: string}  $trend
     * @param  array{state: string}  $regularity
     * @param  array{state: string}  $performance
     * @param  list<array{key: string, count: int}>  $currentAlerts
     * @param  list<array{key: string, count: int}>|null  $previousAlerts
     * @param  list<array{ulid: string, title: string|null, state: string, sentence: string}>  $evolutionAfterStrategy
     * @return list<array{key: string, sentence: string}>
     */
    protected function positiveSignals(
        array $progress,
        array $trend,
        array $regularity,
        array $performance,
        array $currentAlerts,
        ?array $previousAlerts,
        array $evolutionAfterStrategy,
    ): array {
        $signals = [];

        if ($regularity['state'] === 'consistente_a_subir') {
            $signals[] = ['key' => 'consistent_improvement', 'sentence' => 'Observa-se uma melhoria consistente nos elementos mais recentes.'];
        }

        if ($trend['state'] === 'estavel' && $performance['state'] !== 'abaixo_da_turma') {
            $signals[] = ['key' => 'stability', 'sentence' => 'O resultado mantém-se estável, sem sinais de descida.'];
        }

        // WHICH CATEGORY ACTUALLY MOVED, NEVER ONLY THE SUM. Summing across
        // these keys before comparing loses which one(s) improved — a
        // category that got worse could hide behind one that improved more,
        // and the sentence would still say "diminuíram" about a total that
        // is not really telling the truth about either. Compared per key
        // instead, and named with the same labels BuildStudentFactualAlerts
        // already gives these categories, never a new copy of that wording
        // (§4 of the panel review).
        $negativeKeys = ['disciplinary_incidents', 'difficulty_records', 'unresolved_homework'];

        if ($previousAlerts !== null) {
            $recovered = [];

            foreach ($negativeKeys as $key) {
                $before = $this->alertCount($previousAlerts, $key);
                $after = $this->alertCount($currentAlerts, $key);

                if ($before > 0 && $after < $before) {
                    $recovered[] = [
                        'key' => $key,
                        'label' => BuildStudentFactualAlerts::NEGATIVE_LABELS[$key],
                        'from' => $before,
                        'to' => $after,
                    ];
                }
            }

            if ($recovered !== []) {
                $signals[] = [
                    'key' => 'recovery',
                    'sentence' => $this->recoverySentence($recovered, $this->comparisonWindow($progress)),
                ];
            }
        }

        foreach ($evolutionAfterStrategy as $evolution) {
            if ($evolution['state'] === 'positive') {
                $signals[] = ['key' => 'strategy_evolution_'.$evolution['ulid'], 'sentence' => $evolution['sentence']];
            }
        }

        return $signals;
    }

    /**
     * Names the category or categories that improved, with their real
     * before/after counts — never a generic "os registos que requerem
     * atenção" hiding which one actually moved (§4 of the panel review).
     *
     * @param  list<array{key: string, label: string, from: int, to: int}>  $recovered
     * @param  array{from: string, to: string}|null  $window
     */
    protected function recoverySentence(array $recovered, ?array $window): string
    {
        $fromLabel = $window['from'] ?? 'período anterior';
        $toLabel = $window['to'] ?? 'período atual';

        if (count($recovered) === 1) {
            $only = $recovered[0];
            $existed = $this->plural(
                $only['from'],
                'existia :n registo que requeria atenção',
                'existiam :n registos que requeriam atenção',
            );
            $now = $only['to'] === 0
                ? 'não existem registos dessa natureza'
                : $this->plural($only['to'], 'existe :n registo dessa natureza', 'existem :n registos dessa natureza');

            return "No {$fromLabel} {$existed}: {$only['label']}. No {$toLabel} {$now}.";
        }

        $parts = array_map(
            fn (array $row): string => $row['label'].' ('.$row['from'].' → '.$row['to'].')',
            $recovered,
        );

        return "Entre o {$fromLabel} e o {$toLabel}, diminuíram os registos que requerem atenção em mais do que uma categoria: "
            .$this->joinPortuguese($parts).'.';
    }

    // -------------------------------------------------------- potencialidades

    /**
     * Cautious, prudent language only — never a predicted grade or
     * percentage (§5 of the brief). Reuses `margem_progressao` and
     * `domainHighlights` rather than computing anything new.
     *
     * @param  array<string, mixed>  $progress
     * @param  array{state: string, detail: string}  $margin
     * @return array{narrative: string|null, strengths: list<array<string, mixed>>, progressing: list<array<string, mixed>>, next_step: string|null}
     */
    protected function potentialities(array $progress, array $margin): array
    {
        $highest = $progress['domains']['highlights']['highest'] ?? null;
        $rise = $progress['domains']['highlights']['largest_rise'] ?? null;

        if ($margin['state'] === 'sem_elementos') {
            return ['narrative' => null, 'strengths' => [], 'progressing' => [], 'next_step' => null];
        }

        $strengths = [];
        $progressing = [];

        if (is_array($highest) && ($value = Phrase::percentage($highest['value'] ?? null)) !== null) {
            $strengths[] = [
                'domain_id' => (int) $highest['domain_id'],
                'name' => (string) $highest['name'],
                'value' => (string) $highest['value'],
                'detail' => $highest['name'].' — ponto forte atual — '.$value,
            ];
        }

        if (is_array($rise) && ($points = Phrase::number(ltrim((string) ($rise['value'] ?? ''), '+-'))) !== null) {
            $progressing[] = [
                'domain_id' => (int) $rise['domain_id'],
                'name' => (string) $rise['name'],
                'value' => (string) $rise['value'],
                'detail' => $rise['name'].' — maior evolução recente — +'.$points.' p.p.',
            ];
        }

        return [
            'narrative' => $margin['detail'],
            'strengths' => $strengths,
            'progressing' => $progressing,
            'next_step' => $this->nextStep($progress, $strengths, $progressing),
        ];
    }

    /**
     * Synthesises up to THREE roles into one instruction, never only two —
     * a `next_step` that only weighed «ponto forte» and «em progressão»
     * left «Margem de progressão» naming a third, LOWER-priority domain as
     * the one to consolidate, right beside a sentence that never mentioned
     * it (§4 of the panel review, round 2). All three roles read the exact
     * same `domainHighlights` this page already shows elsewhere:
     *
     *  - lowest, via `consolidationPriority()` — the SAME resolution
     *    `consolidationDetail()` uses, so the two sections can never name
     *    two different domains as "the one needing consolidation";
     *  - largest_rise, already resolved above as `$progressing[0]`;
     *  - highest, already resolved above as `$strengths[0]`.
     *
     * A role with no supporting data is simply left out of the sentence.
     * Two (or three) roles that happen to name the SAME domain collapse
     * into ONE clause — never the same domain named twice in one sentence,
     * and never two clauses quietly disagreeing about what to do with it.
     *
     * @param  array<string, mixed>  $progress
     * @param  list<array{domain_id: int, name: string}>  $strengths  this method's own `$strengths`, already resolved
     * @param  list<array{domain_id: int, name: string}>  $progressing  this method's own `$progressing`, already resolved
     */
    protected function nextStep(array $progress, array $strengths, array $progressing): ?string
    {
        $priority = $this->consolidationPriority($progress);
        $rise = $progressing[0] ?? null;
        $highest = $strengths[0] ?? null;

        $roles = array_values(array_filter([
            $priority === null ? null : ['id' => $priority['domain_id'], 'verb' => 'priorizar a consolidação de', 'name' => $priority['name']],
            $rise === null ? null : ['id' => (int) $rise['domain_id'], 'verb' => 'estabilizar a progressão em', 'name' => (string) $rise['name']],
            $highest === null ? null : ['id' => (int) $highest['domain_id'], 'verb' => 'continuar a aprofundar', 'name' => (string) $highest['name']],
        ]));

        if ($roles === []) {
            return null;
        }

        $clauses = [];
        $resolved = [];

        foreach ($roles as $role) {
            if (in_array($role['id'], $resolved, true)) {
                continue;
            }

            $resolved[] = $role['id'];
            $sharing = array_values(array_filter($roles, fn (array $other): bool => $other['id'] === $role['id']));
            $clauses[] = $this->consolidationClause($sharing);
        }

        return Phrase::terminate(Phrase::capitalise(implode('; ', $clauses)));
    }

    /**
     * One clause, for one or more roles that all name the SAME domain.
     *
     * @param  list<array{id: int, verb: string, name: string}>  $roles  one or more roles, all sharing the same domain id
     */
    protected function consolidationClause(array $roles): string
    {
        if (count($roles) === 1) {
            return $roles[0]['verb'].' '.$roles[0]['name'];
        }

        $name = $roles[0]['name'];
        $verbs = array_column($roles, 'verb');

        // The domain in progression IS the one needing consolidation: the
        // subida itself is what is being consolidated, said once.
        if (count($roles) === 2
            && in_array('priorizar a consolidação de', $verbs, true)
            && in_array('estabilizar a progressão em', $verbs, true)) {
            return "priorizar a consolidação da subida recente em {$name}";
        }

        // Any other overlap — the domain in progression is also the
        // strongest one, or a single domain is simultaneously the highest
        // and the lowest — collapses to the same instruction this page
        // already used for "consolidar e aprofundar" (§4 of the panel
        // review).
        return "consolidar e aprofundar {$name}";
    }

    // -------------------------------------------------------- preparar conversa

    /**
     * A synthesis of everything already computed — situation, difficulties,
     * strengths, changes, potentialities, ongoing strategies, self-assessment
     * and topics to discuss — in one compact read. NOT a document: nothing
     * here is exported, and it duplicates no field Relatórios already owns.
     *
     * @param  array<string, mixed>  $progress
     * @param  list<array{key: string, sentence: string, count: int}>  $baseAlerts
     * @param  list<array{key: string, sentence: string}>  $baseStrengths
     * @param  list<array{key: string, sentence: string}>  $analyticalAlerts
     * @param  list<array{key: string, sentence: string}>  $positiveSignals
     * @param  array{narrative: string|null, strengths: list<array<string, mixed>>, progressing: list<array<string, mixed>>, next_step: string|null}  $potentialities
     * @param  array{items: list<array{key: string, sentence: string}>, empty_sentence: string|null}  $whatChanged
     * @param  list<array{ulid: string, title: string|null, state: string, sentence: string}>  $evolutionAfterStrategy
     * @return array<string, mixed>
     */
    protected function conversationPrep(
        array $progress,
        array $baseAlerts,
        array $baseStrengths,
        array $analyticalAlerts,
        array $positiveSignals,
        array $potentialities,
        array $whatChanged,
        array $evolutionAfterStrategy,
    ): array {
        $ongoing = array_values(array_map(fn (array $row): array => [
            'title' => $row['title'],
            'status' => $row['status'],
            'purpose_label' => $row['purpose_label'] ?? null,
            'effectiveness' => $row['effectiveness'] ?? null,
        ], array_filter(
            (array) ($progress['interventions']['rows'] ?? []),
            fn (array $row): bool => ! $row['is_concluded'],
        )));

        $topics = array_values(array_unique(array_merge(
            array_column($baseAlerts, 'sentence'),
            array_column($analyticalAlerts, 'sentence'),
        )));

        return [
            'situation' => [
                'value' => $progress['headline']['value'] ?? null,
                'coverage' => $progress['headline']['coverage'] ?? 'none',
                'reading_label' => $progress['reading']['label'] ?? null,
            ],
            'difficulties' => array_column($baseAlerts, 'sentence'),
            'strengths' => array_column($baseStrengths, 'sentence'),
            'changes' => $whatChanged['items'] === [] ? [$whatChanged['empty_sentence']] : array_column($whatChanged['items'], 'sentence'),
            'potentialities' => implode(' ', array_filter([
                $potentialities['next_step'],
                $potentialities['narrative'],
            ])) ?: null,
            'ongoingStrategies' => $ongoing,
            'selfAssessment' => array_values(array_filter(
                (array) ($progress['selfAssessments'] ?? []),
                fn (array $row): bool => $row['self_assessment'] !== null,
            )),
            'evolutionAfterStrategy' => $evolutionAfterStrategy,
            'positiveSignals' => array_column($positiveSignals, 'sentence'),
            'topicsToDiscuss' => $topics,
        ];
    }

    protected function plural(int $count, string $singular, string $plural): string
    {
        return str_replace(':n', (string) $count, $count === 1 ? $singular : $plural);
    }
}
