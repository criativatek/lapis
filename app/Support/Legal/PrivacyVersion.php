<?php

namespace App\Support\Legal;

use App\Support\Usage\UsageCoverage;
use Carbon\CarbonImmutable;

/**
 * A versão da Política de Privacidade EM VIGOR — e o instante a partir do qual
 * vale.
 *
 * Normalmente é a data escrita à mão em `lapis.legal.privacy_effective_from`. Há
 * uma exceção, e é a razão desta classe: as métricas de utilização
 * (docs/usage-insights.md) só são descritas na Política quando a recolha foi
 * ATIVADA, e o instante dessa ativação é o mesmo registo que marca o início da
 * cobertura (`usage_collection_periods`). Quando é posterior à data escrita, é
 * ele que passa a ser a versão em vigor — por isso o aviso não bloqueante volta
 * a aparecer a toda a gente no momento em que a recolha começa, e não antes nem
 * depois, sem que alguém tenha de se lembrar de mudar uma data no deploy.
 */
final class PrivacyVersion
{
    private const MEMO = 'lapis.privacy.usage_metrics_activated_at';

    /** O instante a partir do qual a versão atual vale, ou nulo sem data configurada. */
    public static function effectiveAt(): ?CarbonImmutable
    {
        $configured = config('lapis.legal.privacy_effective_from');
        $timezone = (string) config('app.timezone');

        $written = is_string($configured) && $configured !== ''
            ? CarbonImmutable::parse($configured, $timezone)->startOfDay()
            : null;

        $activation = self::usageMetricsActivatedAt();

        if ($written === null) {
            return $activation;
        }

        return $activation !== null && $activation->greaterThan($written) ? $activation : $written;
    }

    /** A data (AAAA-MM-DD) que a página mostra como «em vigor desde». */
    public static function effectiveDate(): string
    {
        return self::effectiveAt()?->toDateString() ?? '';
    }

    /** As métricas de utilização já estiveram ativas alguma vez (e por isso a Política descreve-as). */
    public static function describesUsageMetrics(): bool
    {
        return self::usageMetricsActivatedAt() !== null;
    }

    /**
     * Lida UMA vez por pedido: o aviso não bloqueante pergunta-o em cada
     * resposta Inertia, e a página da Política fá-lo meia dúzia de vezes. A
     * memória é do pedido, nunca do processo — um período aberto a meio de
     * outro pedido nunca fica escondido por uma resposta antiga.
     */
    private static function usageMetricsActivatedAt(): ?CarbonImmutable
    {
        $request = app()->bound('request') ? app('request') : null;

        if ($request !== null && $request->attributes->has(self::MEMO)) {
            $memo = $request->attributes->get(self::MEMO);

            return $memo instanceof CarbonImmutable ? $memo : null;
        }

        $activation = UsageCoverage::firstActivation();
        $request?->attributes->set(self::MEMO, $activation ?? false);

        return $activation;
    }
}
