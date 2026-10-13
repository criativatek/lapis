<?php

namespace App\Support\Usage;

/**
 * Para que serve uma organização nas métricas de utilização. Decidido por uma
 * pessoa, nunca inferido; independente da marca comercial `is_test_account`.
 */
enum UsageClassification: string
{
    case Unclassified = 'unclassified';
    case Real = 'real';
    case Test = 'test';
    case Internal = 'internal';

    public function label(): string
    {
        return match ($this) {
            self::Unclassified => 'Por classificar',
            self::Real => 'Utilização real',
            self::Test => 'Teste e demonstração',
            self::Internal => 'Interna',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Unclassified => 'Ninguém decidiu ainda. Não entra na utilização real.',
            self::Real => 'Professores a usar o produto no seu trabalho, incluindo convidados e períodos experimentais.',
            self::Test => 'Dados fictícios, QA, demonstrações e ensaios.',
            self::Internal => 'Organizações da própria equipa, usadas para operação.',
        };
    }
}
