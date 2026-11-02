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
 * cobertura (`usage_collection_periods`, escrito por `lapis:usage-collection
 * start`). Quando é posterior à data escrita, é ele que passa a ser a versão em
 * vigor — por isso o aviso não bloqueante volta a aparecer a toda a gente no
 * momento em que a recolha começa, e não antes nem depois, sem que alguém tenha
 * de se lembrar de mudar uma data no deploy.
 *
 * Desligar a recolha NÃO tira a secção da Política: os períodos fechados
 * continuam a existir, e as métricas já recolhidas continuam conservadas pelos
 * prazos descritos.
 */
final class PrivacyVersion
{
    /**
     * Com quantos dias de antecedência a versão do serviço letivo é ANUNCIADA na aplicação, antes de entrar em
     * vigor. A Política promete «avisar através da aplicação ou por email antes de produzir efeitos»: a faixa
     * aparece a partir de (data − 7 dias), a dizer a data e a dar a ler a versão que vem, e só na data é que os
     * documentos passam a ser os novos.
     */
    public const ADVANCE_NOTICE_DAYS = 7;

    /** O instante a partir do qual a versão atual vale, ou nulo sem data configurada. */
    public static function effectiveAt(): ?CarbonImmutable
    {
        $latest = null;

        foreach ([self::writtenAt(), self::usageMetricsActivatedAt(), self::lessonServiceAt()] as $instant) {
            if ($instant !== null && ($latest === null || $instant->greaterThan($latest))) {
                $latest = $instant;
            }
        }

        return $latest;
    }

    /**
     * O instante a partir do qual o AVISO não bloqueante é novo para quem o fechou antes. Igual a effectiveAt(),
     * excepto na versão do serviço letivo, que se anuncia com antecedência: aí é o início do anúncio (data − 7
     * dias). Quem fechar a faixa durante o anúncio não a volta a ver quando a versão entra em vigor — já a viu.
     */
    public static function noticeAt(): ?CarbonImmutable
    {
        $latest = null;

        foreach ([self::writtenAt(), self::usageMetricsActivatedAt(), self::lessonServiceNoticeAt()] as $instant) {
            if ($instant !== null && ($latest === null || $instant->greaterThan($latest))) {
                $latest = $instant;
            }
        }

        return $latest;
    }

    /**
     * O QUE MUDOU na versão em vigor: `usage_metrics` (a ativação das métricas), `lesson_service` (a versão que
     * descreve as aulas não realizadas, anunciada ou já em vigor) ou `general` (a data escrita à mão). Ganha a
     * mais recente.
     *
     * @return 'general'|'usage_metrics'|'lesson_service'
     */
    public static function latestChange(): string
    {
        $topics = [
            'general' => self::writtenAt(),
            'usage_metrics' => self::usageMetricsActivatedAt(),
            'lesson_service' => self::lessonServiceNoticeAt(),
        ];
        $winner = 'general';
        $latest = null;

        foreach ($topics as $topic => $instant) {
            if ($instant !== null && ($latest === null || $instant->greaterThan($latest))) {
                $winner = $topic;
                $latest = $instant;
            }
        }

        return $winner;
    }

    /**
     * A versão que descreve o serviço letivo JÁ ESTÁ EM VIGOR: há uma data (`lapis.legal.lesson_service_effective_from`)
     * e já chegou. Antes disso — ou sem data — nada do texto novo aparece, e a data em vigor é a anterior.
     */
    public static function describesLessonService(): bool
    {
        return self::lessonServiceAt() !== null;
    }

    /** O instante em que a versão do serviço letivo entra em vigor, ou nulo se não há data válida ou ainda não chegou. */
    public static function lessonServiceAt(): ?CarbonImmutable
    {
        $at = self::lessonServiceDate();

        return $at !== null && $at->lessThanOrEqualTo(self::now()) ? $at : null;
    }

    /**
     * A versão do serviço letivo está ANUNCIADA: há uma data válida, ainda não chegou, e já estamos dentro dos
     * 7 dias de antecedência. É quando a faixa diz «a partir de …» e a página pública dá a ler a versão que vem.
     */
    public static function announcesLessonService(): bool
    {
        return self::lessonServiceNoticeAt() !== null && self::lessonServiceAt() === null;
    }

    /** O início do anúncio (data − 7 dias), ou nulo sem data válida ou se ainda não começou. */
    public static function lessonServiceNoticeAt(): ?CarbonImmutable
    {
        $at = self::lessonServiceDate()?->subDays(self::ADVANCE_NOTICE_DAYS);

        return $at !== null && $at->lessThanOrEqualTo(self::now()) ? $at : null;
    }

    /** A data configurada (`lapis.legal.lesson_service_effective_from`), válida e exacta, independentemente de já ter chegado. */
    public static function lessonServiceDate(): ?CarbonImmutable
    {
        $configured = config('lapis.legal.lesson_service_effective_from');

        if (! is_string($configured) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $configured) !== 1) {
            return null;
        }

        try {
            $at = CarbonImmutable::createFromFormat('Y-m-d', $configured, (string) config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }

        if ($at->toDateString() !== $configured) {
            return null;
        }

        return $at->startOfDay();
    }

    private static function now(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('app.timezone'));
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
     * A versão em vigor é a que a ativação das métricas criou — o aviso não
     * bloqueante diz isso mesmo, e não a novidade de uma versão anterior.
     */
    public static function latestChangeIsUsageMetrics(): bool
    {
        return self::latestChange() === 'usage_metrics';
    }

    private static function writtenAt(): ?CarbonImmutable
    {
        $configured = config('lapis.legal.privacy_effective_from');

        return is_string($configured) && $configured !== ''
            ? CarbonImmutable::parse($configured, (string) config('app.timezone'))->startOfDay()
            : null;
    }

    /**
     * Lida UMA vez por pedido (ou por job): o aviso não bloqueante pergunta-o em
     * cada resposta Inertia, e a página da Política fá-lo meia dúzia de vezes.
     * A memória é `scoped` — nunca do processo —, por isso uma ativação a meio
     * da vida de um worker conta no job seguinte.
     */
    private static function usageMetricsActivatedAt(): ?CarbonImmutable
    {
        $memo = app(PrivacyVersionMemo::class);

        if (! $memo->resolved) {
            $memo->activatedAt = UsageCoverage::firstActivation();
            $memo->resolved = true;
        }

        return $memo->activatedAt;
    }
}
