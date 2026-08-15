<?php

namespace App\Domain\Import\Correction;

/**
 * Where a correction grid came from.
 *
 * These are FILE FORMATS exported by other platforms, not integrations. LÁPIS
 * does not talk to Plickers or Intuitivo; it reads what a teacher exported from
 * them. Nothing here should ever be read as a promise of an API.
 *
 * Cases exist ahead of their parsers on purpose: the registry answers "is this
 * supported yet?" from the parsers actually registered, so an unimplemented
 * source is a clean "not yet" rather than a missing enum case somebody has to
 * add under pressure.
 */
enum CorrectionGridSource: string
{
    case Plickers = 'plickers';
    case Intuitivo = 'intuitivo';
    case Generic = 'generic';

    public function label(): string
    {
        return match ($this) {
            self::Plickers => 'Plickers',
            self::Intuitivo => 'Intuitivo',
            self::Generic => __('Folha de cálculo (Excel/CSV)'),
        };
    }

    /**
     * What the teacher needs to have done before choosing this source.
     */
    public function hint(): string
    {
        return match ($this) {
            self::Plickers => __('O ficheiro CSV exportado do Plickers, com as respostas dos alunos.'),
            self::Intuitivo => __('A folha de notas exportada do Intuitivo.'),
            self::Generic => __('Uma folha de cálculo com os resultados por aluno e por questão.'),
        };
    }
}
