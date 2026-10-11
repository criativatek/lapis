<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Recolha de métricas de utilização
    |--------------------------------------------------------------------------
    |
    | Interruptor geral da recolha (docs/usage-insights.md). Desligá-lo não
    | apaga nada: `usage:aggregate` fecha o período de recolha em curso em
    | `usage_collection_periods`, e os dias seguintes aparecem como «sem
    | recolha» — nunca como zeros.
    |
    */

    'enabled' => (bool) env('LAPIS_USAGE_ENABLED', true),

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
