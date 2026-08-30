<?php

namespace App\Actions\Commercial;

use App\Models\Organization;
use App\Models\User;
use App\Models\VoucherRedemption;
use App\Services\Audit\AuditLog;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Devolve ao voucher a capacidade de uma reserva que deixou de valer.
 *
 * SÓ RESERVAS. Uma linha confirmada é a prova de uma condição comercial e o
 * modelo recusa apagá-la — este action nem tenta: lança antes de tocar. O
 * caminho normal até aqui é o `revalidate()` do fluxo bancário, quando o pedido
 * pendente que segurava a reserva caduca ou é anulado; a limpeza oportunista
 * dentro de `RedeemVoucher` apanha as que ninguém visitou.
 *
 * APAGAR É O MECANISMO, e não uma coluna `released_at`: é a eliminação que
 * mantém o `UNIQUE(voucher_id, organization_id)` a dizer «no máximo uma linha
 * viva por par» e que deixa a organização voltar a tentar. O rasto fica na
 * auditoria, com autoria e motivo — uma reserva morta não é estado comercial,
 * mas a sua morte é um acontecimento.
 */
class ReleaseVoucherRedemption
{
    public function __construct(
        protected AuditLog $audit,
        protected CurrentOrganization $currentOrganization,
    ) {}

    public function release(VoucherRedemption $redemption, Organization $organization, string $reason, ?User $actor = null): void
    {
        if ($redemption->isConfirmed()) {
            throw new LogicException(
                'A confirmed voucher redemption is never released: it is the proof of a commercial condition.'
            );
        }

        DB::transaction(function () use ($redemption, $organization, $reason, $actor): void {
            $voucher = $redemption->voucher()->firstOrFail();

            $redemption->delete();

            $this->currentOrganization->runFor($organization, fn () => $this->audit->record(
                'commercial.voucher_reservation_released',
                $organization,
                $actor,
                summary: sprintf('Reserva do voucher %s libertada: %s', $voucher->code, $reason),
                properties: [
                    'voucher_code' => $voucher->code,
                    'voucher_id' => $voucher->getKey(),
                    'redemption_ulid' => $redemption->ulid,
                    'reason' => $reason,
                ],
            ));
        });
    }
}
