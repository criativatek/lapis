<?php

namespace App\Actions\Usage;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A oposição à recolha de métricas de utilização («Não registar a minha
 * utilização», nas definições da conta; docs/usage-insights.md, «Direito de
 * oposição»).
 *
 * OPOR-SE: grava `usage_opted_out_at` e apaga, na mesma transação, tudo o que
 * as métricas guardam sobre a pessoa — eventos, agregados e primeiros passos.
 * Com a linha da pessoa bloqueada: um pedido que estava a meio e tenta gravar
 * depois (UsageRecorder::write()) espera por este commit e já vê a oposição; a
 * agregação ignora e volta a apagar quem se opôs em cada passagem. Nada mais
 * muda: dados pedagógicos, funcionalidades e acesso ficam exatamente iguais.
 *
 * RETIRAR A OPOSIÇÃO: a recolha volta a valer a partir DESTE instante
 * (`usage_resumed_at`), sem histórico — o que foi apagado não volta, e o
 * painel mostra o que vem antes como «sem recolha», nunca como zero.
 *
 * Idempotente nos dois sentidos: repetir não muda datas e não deixa restos.
 */
class SetUsageOptOut
{
    public function __construct(protected ForgetUsageData $forget) {}

    /** @return bool se o estado mudou */
    public function optOut(User $user): bool
    {
        return DB::transaction(function () use ($user): bool {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $changed = $locked->usage_opted_out_at === null;

            if ($changed) {
                $locked->forceFill(['usage_opted_out_at' => now(), 'usage_resumed_at' => null])->save();
            }

            // Mesmo repetida, apaga: nunca ficam restos.
            $this->forget->forUser($locked);

            $user->forceFill([
                'usage_opted_out_at' => $locked->usage_opted_out_at,
                'usage_resumed_at' => $locked->usage_resumed_at,
            ])->syncOriginal();

            return $changed;
        });
    }

    /** @return bool se o estado mudou */
    public function optIn(User $user): bool
    {
        return DB::transaction(function () use ($user): bool {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->usage_opted_out_at === null) {
                return false;
            }

            $locked->forceFill(['usage_opted_out_at' => null, 'usage_resumed_at' => now()])->save();

            $user->forceFill([
                'usage_opted_out_at' => null,
                'usage_resumed_at' => $locked->usage_resumed_at,
            ])->syncOriginal();

            return true;
        });
    }
}
