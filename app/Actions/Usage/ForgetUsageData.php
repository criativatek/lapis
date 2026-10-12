<?php

namespace App\Actions\Usage;

use App\Models\UsageDailyRollup;
use App\Models\UsageEvent;
use App\Models\UsageUserState;
use App\Models\User;

/**
 * Apaga tudo o que as métricas de utilização guardam sobre uma pessoa: eventos,
 * agregados e datas de primeira observação. Usada na oposição à recolha
 * (`SetUsageOptOut`), no PEDIDO de encerramento da conta
 * (`RequestPersonalAccountClosure`) e, por segurança, na anonimização
 * (`AnonymiseClosedAccount`) — sempre dentro da transação de quem chama, com a
 * linha da pessoa bloqueada.
 */
class ForgetUsageData
{
    /**
     * @return int quantos eventos foram removidos
     */
    public function forUser(User $user): int
    {
        $events = UsageEvent::query()->where('user_id', $user->getKey())->delete();
        UsageDailyRollup::query()->where('user_id', $user->getKey())->delete();
        UsageUserState::query()->where('user_id', $user->getKey())->delete();

        return $events;
    }
}
