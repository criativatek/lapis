<?php

namespace App\Support\Organizations;

use RuntimeException;

/**
 * Thrown when an invitation cannot be accepted — every reason the link at the
 * end of the email might no longer work (Fatia 3).
 */
class InvitationException extends RuntimeException
{
    public static function notFound(): self
    {
        return new self(__('Este convite não existe ou já não é válido.'));
    }

    public static function cancelled(): self
    {
        return new self(__('Este convite foi cancelado.'));
    }

    public static function alreadyAccepted(): self
    {
        return new self(__('Este convite já foi aceite.'));
    }

    public static function expired(): self
    {
        return new self(__('Este convite expirou. Peça ao responsável da organização para o reenviar.'));
    }

    public static function emailMismatch(): self
    {
        return new self(__('Este convite foi enviado para outro email. Termine a sessão atual para o aceitar.'));
    }
}
