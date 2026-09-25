<?php

namespace App\Domain\Assessment\Analysis;

use App\Domain\Assessment\Bc;

/**
 * A deterministic descriptive report built from an already-computed analysis
 * — no AI, no interpretation beyond the statistical facts spec'd in §5.
 * Never a student name, never an individual value. Never a cause.
 *
 * Section order is fixed: identification · global_summary · quantitative ·
 * qualitative · domains · differences. The caller adds `generated_at` and the
 * professor's own notes — this class never sees them.
 */
final class DescriptiveReport
{
    /**
     * @param  array<string, mixed>  $context
     * @param  list<array<string, mixed>>  $dimensions  each: key, label, analysis
     * @return array{title: string, sections: list<array<string, mixed>>}
     */
    public static function compose(array $context, array $dimensions): array
    {
        $global = self::dimension($dimensions, 'global');
        $domains = array_values(array_filter($dimensions, fn (array $d): bool => $d['key'] !== 'global'));

        return [
            'title' => $context['is_diagnostic']
                ? 'Relatório da avaliação diagnóstica'
                : 'Relatório estatístico do instrumento',
            'sections' => [
                self::identification($context),
                self::globalSummary($context, $global),
                self::quantitative($global),
                self::qualitative($global),
                self::domains($domains),
                self::differences($context, $global, $domains),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $dimensions
     * @return array<string, mixed>|null
     */
    private static function dimension(array $dimensions, string $key): ?array
    {
        foreach ($dimensions as $dimension) {
            if ($dimension['key'] === $key) {
                return $dimension;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function identification(array $context): array
    {
        $paragraphs = [
            sprintf(
                '%s — %s, aplicado em %s.',
                $context['instrument']['title'],
                $context['class']['label'],
                implode('/', array_reverse(explode('-', (string) $context['instrument']['applied_on']))),
            ),
            sprintf('Período: %s. Finalidade: %s.', $context['period']['label'], $context['instrument']['purpose_label']),
        ];

        if ($context['is_diagnostic'] && ! $context['counts_toward_classification']) {
            $paragraphs[] = 'Finalidade diagnóstica: os resultados servem para identificar potencialidades, dificuldades e '
                .'necessidades de acompanhamento. Este instrumento está configurado para não contar para a classificação, '
                .'e por isso não entra nas médias classificativas do período.';
        }

        if ($context['is_diagnostic'] && $context['counts_toward_classification']) {
            $paragraphs[] = 'Aviso: este instrumento diagnóstico está configurado para contar para a classificação — '
                .'os seus resultados ENTRAM atualmente no cálculo do período, apesar de a finalidade ser diagnóstica.';
        }

        if ($context['is_diagnostic']) {
            $paragraphs[] = 'A exclusão dos instrumentos diagnósticos depende hoje dessa configuração; a garantia no motor '
                .'de classificação, independente dela, ainda não está implementada.';
        }

        return self::section('identification', 'Identificação', $paragraphs);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $global
     * @return array<string, mixed>
     */
    private static function globalSummary(array $context, ?array $global): array
    {
        if ($global === null) {
            return self::section('global_summary', 'Síntese global', ['Sem dados para análise.']);
        }

        $analysis = $global['analysis'];
        $paragraphs = [
            sprintf(
                '%s abrangidos pelo instrumento; %s (%s do universo).',
                self::students($analysis['universe']),
                self::plural($analysis['classified'], 'aluno avaliado', 'alunos avaliados'),
                self::percent(self::sharePercent($analysis['classified'], $analysis['universe'])),
            ),
        ];

        if ($analysis['classified'] > 0) {
            $paragraphs[] = sprintf(
                'Média: %s. Mediana: %s.',
                self::percent($analysis['mean']),
                self::percent($analysis['median']),
            );

            if ($analysis['threshold']['available']) {
                $paragraphs[] = sprintf(
                    'Classificações inferiores a %s: %s (%s). Iguais ou superiores a %s: %s (%s). Percentagens sobre os alunos avaliados.',
                    self::percent($analysis['threshold']['value']),
                    self::plural($analysis['threshold']['below']['count'], 'aluno', 'alunos'),
                    self::percent($analysis['threshold']['below']['percent']),
                    self::percent($analysis['threshold']['value']),
                    self::plural($analysis['threshold']['at_or_above']['count'], 'aluno', 'alunos'),
                    self::percent($analysis['threshold']['at_or_above']['percent']),
                );
            } else {
                $paragraphs[] = 'A escala configurada não define uma fronteira quantitativa inequívoca entre apreciações '
                    .'negativas e não negativas; não é apresentada uma repartição por limiar.';
            }
        }

        if ($analysis['missing']['total'] > 0) {
            $paragraphs[] = 'Sem classificação, e por isso fora dos indicadores: '.self::missingBreakdown($analysis['missing']).'.';
        }

        if ($analysis['partial'] > 0) {
            $paragraphs[] = self::plural($analysis['partial'], 'resultado parcial', 'resultados parciais').' — ainda com itens por corrigir.';
        }

        return self::section('global_summary', 'Síntese global', $paragraphs);
    }

    /**
     * @param  array<string, mixed>|null  $global
     * @return array<string, mixed>
     */
    private static function quantitative(?array $global): array
    {
        if ($global === null || $global['analysis']['classified'] === 0) {
            return self::section('quantitative', 'Distribuição quantitativa', ['Sem dados para análise.'], null);
        }

        $analysis = $global['analysis'];
        $rows = [];
        foreach ($analysis['quantitative']['classes'] as $class) {
            $rows[] = [$class['label'], (string) $class['count'], self::percent($class['percent'])];
        }

        return self::section(
            'quantitative',
            'Distribuição quantitativa',
            ['Distribuição dos resultados globais por classe de 10 pontos percentuais, sobre o valor exato.'],
            ['columns' => ['Classe', 'N', '%'], 'rows' => $rows],
        );
    }

    /**
     * @param  array<string, mixed>|null  $global
     * @return array<string, mixed>
     */
    private static function qualitative(?array $global): array
    {
        if ($global === null) {
            return self::section('qualitative', 'Distribuição qualitativa', ['Sem dados para análise.'], null);
        }

        $qualitative = $global['analysis']['qualitative'];

        if (! $qualitative['available']) {
            return self::section(
                'qualitative',
                'Distribuição qualitativa',
                ['A escala da turma não tem bandas configuradas — distribuição qualitativa indisponível.'],
                null,
            );
        }

        $rows = [];
        foreach ($qualitative['categories'] as $category) {
            $rows[] = [$category['label'], (string) $category['count'], self::percent($category['percent'])];
        }

        if ($qualitative['unplaced'] > 0) {
            $rows[] = ['Sem apreciação', (string) $qualitative['unplaced'], self::percent(
                self::sharePercent($qualitative['unplaced'], $qualitative['total']),
            )];
        }

        return self::section(
            'qualitative',
            'Distribuição qualitativa',
            ['Distribuição dos resultados globais pelos níveis da escala da turma.'],
            ['columns' => ['Nível', 'N', '%'], 'rows' => $rows],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $domains
     * @return array<string, mixed>
     */
    private static function domains(array $domains): array
    {
        if ($domains === []) {
            return self::section('domains', 'Resultados por domínio', ['Este instrumento não toca nenhum domínio do perfil da turma.'], null);
        }

        // The «% abaixo do limiar» column only makes sense when the scale
        // defines one — checked once, on any domain's analysis, since the
        // threshold is the same scale for every dimension of one instrument.
        $thresholdAvailable = (bool) ($domains[0]['analysis']['threshold']['available'] ?? false);

        $columns = ['Domínio', 'N', 'Média', 'Mediana'];
        if ($thresholdAvailable) {
            $columns[] = '% abaixo do limiar';
        }

        $rows = [];
        foreach ($domains as $domain) {
            $analysis = $domain['analysis'];
            $row = [
                $domain['label'],
                (string) $analysis['classified'],
                $analysis['mean'] !== null ? self::percent($analysis['mean']) : '—',
                $analysis['median'] !== null ? self::percent($analysis['median']) : '—',
            ];

            if ($thresholdAvailable) {
                $row[] = $analysis['classified'] > 0 && $analysis['threshold']['available']
                    ? self::percent($analysis['threshold']['below']['percent'])
                    : '—';
            }

            $rows[] = $row;
        }

        return self::section(
            'domains',
            'Resultados por domínio',
            ['Estatística descritiva por domínio tocado por este instrumento.'],
            ['columns' => $columns, 'rows' => $rows],
        );
    }

    /**
     * Only statistical facts (§5). Never a cause.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $global
     * @param  list<array<string, mixed>>  $domains
     * @return array<string, mixed>
     */
    private static function differences(array $context, ?array $global, array $domains): array
    {
        $paragraphs = [];

        $withValues = array_values(array_filter($domains, fn (array $d): bool => $d['analysis']['classified'] > 0));

        if (count($withValues) >= 2) {
            $highest = $withValues[0];
            $lowest = $withValues[0];
            foreach ($withValues as $domain) {
                if (Bc::compare($domain['analysis']['mean'], $highest['analysis']['mean']) > 0) {
                    $highest = $domain;
                }
                if (Bc::compare($domain['analysis']['mean'], $lowest['analysis']['mean']) < 0) {
                    $lowest = $domain;
                }
            }

            if ($highest['key'] !== $lowest['key']) {
                $gap = Bc::round(Bc::sub(Bc::of($highest['analysis']['mean']), Bc::of($lowest['analysis']['mean'])), 1, 'half_up');
                $paragraphs[] = sprintf(
                    'O domínio com média mais alta é «%s» (%s) e o de média mais baixa é «%s» (%s) — diferença de %s pontos percentuais.',
                    $highest['label'],
                    self::percent($highest['analysis']['mean']),
                    $lowest['label'],
                    self::percent($lowest['analysis']['mean']),
                    self::number($gap),
                );
            }

            $largestBelow = null;
            foreach ($withValues as $domain) {
                if (! $domain['analysis']['threshold']['available']) {
                    continue;
                }

                $share = $domain['analysis']['threshold']['below']['percent'];
                if ($share === null) {
                    continue;
                }
                if ($largestBelow === null || Bc::compare($share, $largestBelow['analysis']['threshold']['below']['percent']) > 0) {
                    $largestBelow = $domain;
                }
            }

            if ($largestBelow !== null) {
                $paragraphs[] = sprintf(
                    'O domínio «%s» tem a maior proporção de alunos abaixo do limiar (%s).',
                    $largestBelow['label'],
                    self::percent($largestBelow['analysis']['threshold']['below']['percent']),
                );
            }
        }

        if ($global !== null && $global['analysis']['classified'] > 0) {
            $mean = $global['analysis']['mean'];
            $median = $global['analysis']['median'];
            $gap = Bc::sub(Bc::of($mean), Bc::of($median));
            $absGap = Bc::of(str_starts_with($gap, '-') ? substr($gap, 1) : $gap);

            if (Bc::compare($absGap, '5') >= 0) {
                $paragraphs[] = sprintf(
                    'A média (%s) e a mediana (%s) globais divergem em %s pontos percentuais — indício de assimetria na distribuição.',
                    self::percent($mean),
                    self::percent($median),
                    self::number(Bc::round($absGap, 1, 'half_up')),
                );
            }

            if ($global['analysis']['missing']['total'] > 0) {
                $paragraphs[] = self::plural($global['analysis']['missing']['total'], 'aluno sem classificação', 'alunos sem classificação')
                    .' neste instrumento ('.self::missingBreakdown($global['analysis']['missing']).'), que não entram nos indicadores.';
            }

            if ($global['analysis']['partial'] > 0) {
                $paragraphs[] = self::plural($global['analysis']['partial'], 'resultado parcial', 'resultados parciais')
                    .' — os valores apresentados podem ainda mudar quando a correção terminar.';
            }

            if ($global['analysis']['classified'] < 5) {
                $paragraphs[] = 'Cautela: apenas '.self::plural($global['analysis']['classified'], 'aluno avaliado', 'alunos avaliados')
                    .' — as medidas estatísticas têm valor limitado com um número tão reduzido.';
            }
        }

        if ($paragraphs === []) {
            $paragraphs[] = 'Sem diferenças estatísticas relevantes a assinalar.';
        }

        return self::section('differences', 'Principais diferenças estatísticas observadas', $paragraphs);
    }

    /**
     * @param  list<string>  $paragraphs
     * @param  array{columns: list<string>, rows: list<list<string>>}|null  $table
     * @return array<string, mixed>
     */
    private static function section(string $key, string $title, array $paragraphs, ?array $table = null): array
    {
        return ['key' => $key, 'title' => $title, 'paragraphs' => $paragraphs, 'table' => $table];
    }

    private static function sharePercent(int $count, int $denominator): ?string
    {
        if ($denominator === 0) {
            return null;
        }

        return Bc::round(Bc::mul(Bc::div((string) $count, (string) $denominator), '100'), 1, 'half_up');
    }

    private static function percent(?string $value): string
    {
        if ($value === null) {
            return 'sem valores';
        }

        return self::number($value).' %';
    }

    private static function plural(int $count, string $singular, string $plural): string
    {
        return $count === 1 ? "1 {$singular}" : "{$count} {$plural}";
    }

    private static function students(int $count): string
    {
        return self::plural($count, 'aluno', 'alunos');
    }

    /**
     * «2 ausentes, 1 dispensado» — only the non-zero reasons, in a fixed order.
     *
     * @param  array<string, int>  $missing
     */
    private static function missingBreakdown(array $missing): string
    {
        $labels = [
            'pending' => ['por classificar', 'por classificar'],
            'under_review' => ['em revisão', 'em revisão'],
            'absent' => ['ausente', 'ausentes'],
            'absent_justified' => ['com ausência justificada', 'com ausência justificada'],
            'exempt' => ['dispensado', 'dispensados'],
            'not_applicable' => ['não aplicável', 'não aplicáveis'],
            'annulled' => ['anulado', 'anulados'],
        ];

        $parts = [];
        foreach ($labels as $key => [$singular, $plural]) {
            $count = (int) ($missing[$key] ?? 0);
            if ($count > 0) {
                $parts[] = self::plural($count, $singular, $plural);
            }
        }

        return implode(', ', $parts);
    }

    private static function number(string $value): string
    {
        return str_replace('.', ',', $value);
    }
}
