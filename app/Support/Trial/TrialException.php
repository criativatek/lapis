<?php

namespace App\Support\Trial;

use RuntimeException;

class TrialException extends RuntimeException
{
    public static function notEligible(): self
    {
        return new self(__('Este tipo de organização não é elegível para o período experimental Pro.'));
    }

    public static function notOwner(): self
    {
        return new self(__('Só o responsável pela organização pode ativar o período experimental Pro.'));
    }

    public static function alreadyUsed(): self
    {
        return new self(__('Esta conta já utilizou o período experimental Pro.'));
    }
}
