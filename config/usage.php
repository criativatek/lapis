<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Recolha de métricas de utilização
    |--------------------------------------------------------------------------
    |
    | DESLIGADA POR OMISSÃO até à decisão de ativação
    | (docs/usage-insights-decisions.md). Desligada, nada é gravado, a
    | agregação não lê dados de pessoas e a Política de Privacidade não muda.
    | Ligada, o primeiro registo possível (ou a passagem seguinte da agregação)
    | abre o período de recolha em `usage_collection_periods`: a cobertura e a
    | versão da Política começam nesse instante, e nada é gravado antes dele.
    | Voltar a desligar fecha o período; os dias seguintes aparecem como «sem
    | recolha», nunca como zeros, e não geram sinais.
    |
    */

    'enabled' => (bool) env('LAPIS_USAGE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Frescura da agregação
    |--------------------------------------------------------------------------
    |
    | `usage:aggregate` corre a cada 15 minutos. Se a última agregação tiver
    | mais do que isto, a atividade e o estado das turmas que o painel lê podem
    | estar parados (scheduler parado, comando a falhar): os sinais que
    | dependem deles ficam «por avaliar» em vez de acusarem toda a gente de
    | não ter atividade recente, e o painel avisa.
    |
    */

    'max_staleness_minutes' => (int) env('LAPIS_USAGE_MAX_STALENESS_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Sinais de acompanhamento
    |--------------------------------------------------------------------------
    |
    | Sugestões de VERIFICAÇÃO para a equipa interna, não diagnósticos. Cada
    | regra pode ser desligada. Os prazos são dias de calendário, menos os dias
    | não letivos registados no calendário do ano letivo, quando existem.
    | Ver docs/usage-insights.md, «Sinais de acompanhamento».
    |
    */

    'signals' => [

        // Conta criada há pelo menos `days` dias e sem nenhuma turma disponível.
        'no_class' => [
            'enabled' => true,
            'days' => 7,
        ],

        // Turma disponível há pelo menos `days` dias e nenhum registo útil observado desde então.
        'no_first_record' => [
            'enabled' => true,
            'days' => 7,
        ],

        // Atividade em pelo menos `min_active_weeks` das `lookback_weeks` semanas
        // completas anteriores e nenhuma nos últimos `quiet_days` dias.
        'went_quiet' => [
            'enabled' => true,
            'quiet_days' => 14,
            'lookback_weeks' => 4,
            'min_active_weeks' => 2,
        ],

        // Pelo menos `threshold` falhas técnicas nos últimos `days` dias.
        'technical_failures' => [
            'enabled' => true,
            'days' => 7,
            'threshold' => 3,
        ],

    ],

];
