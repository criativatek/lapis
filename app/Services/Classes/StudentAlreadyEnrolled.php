<?php

namespace App\Services\Classes;

use RuntimeException;

/**
 * Uma recusa de negócio conhecida, com a frase que o professor lê — não uma
 * avaria. SupportClassStudentController transforma-a
 * num erro de validação, nunca num 500.
 */
class StudentAlreadyEnrolled extends RuntimeException
{
    public static function inThisClass(): self
    {
        return new self('Este aluno já pertence a esta turma.');
    }

    public static function onThisDate(): self
    {
        return new self('Este aluno já tem uma inscrição nesta turma com esta data de entrada.');
    }
}
