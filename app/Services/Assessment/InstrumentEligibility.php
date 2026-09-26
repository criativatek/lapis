<?php

namespace App\Services\Assessment;

use App\Models\Instrument;
use App\Models\InstrumentStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * A ÚNICA DEFINIÇÃO de «este instrumento entra nas médias» — o motor
 * (`ClassResultsCalculator::instrumentsInScope`), a guarda de publicação e a
 * prontidão da pauta perguntam aqui, em vez de repetirem o filtro.
 *
 * Entra nas médias quem, ao mesmo tempo:
 *  - está marcado «Conta para a classificação»;
 *  - NÃO é diagnóstico — um diagnóstico nunca conta, seja qual for o valor
 *    gravado, o estado, ou o caminho por onde chegou (decisão do proprietário,
 *    JANELA AG);
 *  - está num estado que o motor lê (`InstrumentStatus::entersCalculation()`,
 *    inalterado: um instrumento em correção continua a contar com os
 *    resultados que já tem).
 *
 * Os valores de cada aluno NO PRÓPRIO instrumento (grelha, Resultados,
 * Evolução do Aluno) não passam por aqui: um diagnóstico continua a ter
 * resultados, estatísticas e relatório — só não entra nas médias.
 */
class InstrumentEligibility
{
    public const DIAGNOSTIC = 'diagnostic';

    public function isDiagnostic(Instrument $instrument): bool
    {
        return $instrument->purpose === self::DIAGNOSTIC;
    }

    /**
     * A configuração efetiva, ignorando o estado — o que um ecrã deve mostrar
     * como «conta / não conta». Um diagnóstico nunca conta, mesmo que uma
     * linha antiga diga o contrário.
     */
    public function isConfiguredToCount(Instrument $instrument): bool
    {
        return (bool) $instrument->counts_toward_classification && ! $this->isDiagnostic($instrument);
    }

    public function contributesToAverages(Instrument $instrument): bool
    {
        return $this->isConfiguredToCount($instrument) && $instrument->status->entersCalculation();
    }

    /**
     * A forma SQL de contributesToAverages(), para quem consulta em bloco.
     *
     * @param  Builder<Instrument>  $query
     * @return Builder<Instrument>
     */
    public function constrain(Builder $query): Builder
    {
        $statuses = array_values(array_filter(
            InstrumentStatus::cases(),
            fn (InstrumentStatus $status): bool => $status->entersCalculation(),
        ));

        return $query
            ->where('counts_toward_classification', true)
            ->where(fn (Builder $q) => $q->whereNull('purpose')->orWhere('purpose', '!=', self::DIAGNOSTIC))
            ->whereIn('status', array_map(fn (InstrumentStatus $status): string => $status->value, $statuses));
    }
}
