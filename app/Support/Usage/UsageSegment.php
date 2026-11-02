<?php

namespace App\Support\Usage;

/**
 * Os segmentos do painel, por classificação explícita das contas
 * (`UsageClassification`). O pessoal interno (`is_platform_admin`,
 * `is_support_technician`) pertence sempre a `internal`.
 */
enum UsageSegment: string
{
    case Real = 'real';
    case Test = 'test';
    case Internal = 'internal';
    case Unclassified = 'unclassified';

    public function label(): string
    {
        return match ($this) {
            self::Real => 'Utilização real',
            self::Test => 'Teste e demonstração',
            self::Internal => 'Interno',
            self::Unclassified => 'Por classificar',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Real => 'Contas classificadas como utilização real, sem o pessoal interno.',
            self::Test => 'Contas classificadas como teste e demonstração: dados fictícios, QA, ensaios.',
            self::Internal => 'Contas internas e toda a atividade do pessoal interno, onde quer que seja.',
            self::Unclassified => 'Contas que ninguém classificou ainda; não entram na utilização real.',
        };
    }
}
