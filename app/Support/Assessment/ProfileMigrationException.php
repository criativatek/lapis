<?php

namespace App\Support\Assessment;

use RuntimeException;

/**
 * Thrown when a class cannot migrate to the requested profile version: the target
 * is not active, or it is the version the class already uses (§10.2, A4).
 */
class ProfileMigrationException extends RuntimeException
{
    public static function notActive(): self
    {
        return new self(__('Só um perfil ativo pode ser associado a uma turma.'));
    }

    public static function sameVersion(): self
    {
        return new self(__('A turma já usa esta versão de perfil.'));
    }
}
