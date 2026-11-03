<?php

namespace App\Support\Usage;

/**
 * Para que serve uma conta (organização) nas métricas de utilização. O valor de origem é
 * `Real`; só uma pessoa o muda, e nunca é inferido; independente da marca comercial `is_test_account`.
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
            self::Unclassified => 'Um operador olhou e ainda não decidiu. Não entra na utilização real.',
            self::Real => 'Professores a usar o produto no seu trabalho, incluindo convidados e períodos experimentais. É o valor de origem de toda a conta nova, até um operador a marcar de outra forma.',
            self::Test => 'Dados fictícios, QA, demonstrações e ensaios.',
            self::Internal => 'Contas da própria equipa, usadas para operação.',
        };
    }
}
