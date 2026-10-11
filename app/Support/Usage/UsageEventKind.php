<?php

namespace App\Support\Usage;

enum UsageEventKind: string
{
    /** Consulta deliberada: uma visita completa a uma página de uma funcionalidade. */
    case View = 'view';

    /** Ação concluída, confirmada pelo servidor depois do commit. */
    case Action = 'action';

    /** Falha técnica numa operação de uma funcionalidade. */
    case Failure = 'failure';
}
