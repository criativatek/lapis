<?php

namespace App\Support\Export;

use RuntimeException;

/**
 * Thrown when the uploaded grid is not one this can safely fill in.
 *
 * Every message here is meant to be shown to a teacher, so each says what is
 * wrong with THEIR file rather than what went wrong inside. Filling a grid whose
 * structure was guessed at is worse than filling none: the school would upload
 * it, and nobody would find out until the marks were wrong.
 */
class InovarTemplateException extends RuntimeException
{
    public static function notRecognized(string $detail): self
    {
        return new self(__('Esta grelha não tem a estrutura esperada do INOVAR. :detail', ['detail' => $detail]));
    }

    public static function unreadable(string $detail): self
    {
        return new self(__('Não foi possível ler este ficheiro. Confirme que é a grelha .xls exportada pelo INOVAR. (:detail)', ['detail' => $detail]));
    }
}
