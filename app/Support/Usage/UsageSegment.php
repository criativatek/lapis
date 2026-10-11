<?php

namespace App\Support\Usage;

enum UsageSegment: string
{
    /** Contas reais: fora das contas de teste e do pessoal interno. */
    case Main = 'main';

    /** «Teste e internas». */
    case Test = 'test';

    public function label(): string
    {
        return match ($this) {
            self::Main => 'Principal',
            self::Test => 'Teste e internas',
        };
    }
}
