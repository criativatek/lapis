<?php

namespace App\Actions\Support;

use App\Models\SupportAuthorRole;
use App\Models\SupportMessage;
use App\Models\SupportRequest;
use App\Models\SupportRequestStatus;
use App\Models\SupportTechnicalCode;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mudar o estado de um pedido, e classificá-lo tecnicamente.
 *
 * CADA ESTADO ARRUMA OS SEUS CARIMBOS, e é por isso que isto vive num só sítio:
 * `waiting_for_user` sem `waiting_since` é recusado pela base de dados, e
 * `resolved` sem `resolved_at` também. Espalhar estas escritas pelos
 * controladores seria dar a cada um a hipótese de esquecer um campo.
 *
 * `technical_code` É SEMPRE DE UM OPERADOR. Nada o infere — nem do texto, nem
 * da categoria que a pessoa escolheu, nem da rota que o ecrã enviou. Deduzir
 * «isto é um erro de importação» é a mesma família de erro que deduzir «isto é
 * uma conta de teste» pelo domínio do email.
 */
class ChangeSupportStatus
{
    public function __construct(protected AuditLog $audit) {}

    public function to(SupportRequest $request, SupportRequestStatus $status, User $operator): SupportRequest
    {
        $anterior = $request->status;

        if ($anterior === $status) {
            return $request;
        }

        return DB::transaction(function () use ($request, $status, $operator, $anterior): SupportRequest {
            $request->forceFill([
                'status' => $status,
                // O relógio da espera existe no estado da espera, e em mais
                // nenhum. Sair dele apaga também o carimbo do lembrete: uma
                // espera futura é uma espera nova e merece o seu próprio aviso.
                'waiting_since' => $status->waits() ? Carbon::now() : null,
                'waiting_reminder_sent_at' => null,
                'resolved_at' => $status->isResolved() ? Carbon::now() : null,
                'resolved_by' => $status->isResolved() ? $operator->getKey() : null,
                // Resolvido por uma pessoa é o contrário de resolvido pelo
                // tempo. Ver `SupportRetention`.
                'auto_resolved' => false,
            ])->save();

            $this->audit->recordPlatform(
                $status->isResolved() ? 'support.resolved' : 'support.status_changed',
                $operator,
                'Pedido de suporte '.$request->reference.': '.$anterior->value.' → '.$status->value.'.',
                ['reference' => $request->reference, 'from' => $anterior->value, 'to' => $status->value],
            );

            return $request;
        });
    }

    /** A classificação interna, atribuída por quem olhou. */
    public function classify(SupportRequest $request, ?SupportTechnicalCode $code, User $operator): SupportRequest
    {
        $anterior = $request->technical_code;

        return DB::transaction(function () use ($request, $code, $operator, $anterior): SupportRequest {
            $request->forceFill(['technical_code' => $code])->save();

            $this->audit->recordPlatform(
                'support.classified',
                $operator,
                'Pedido de suporte '.$request->reference.' classificado.',
                [
                    'reference' => $request->reference,
                    'from' => $anterior?->value,
                    'to' => $code?->value,
                ],
            );

            return $request;
        });
    }

    /**
     * A mensagem que o sistema deixa quando fecha um pedido sozinho.
     *
     * Sem ela, um pedido fechava-se e o histórico não dizia porquê — e quem o
     * abrisse um ano depois leria um fim sem explicação.
     */
    public function appendSystemNote(SupportRequest $request, string $body): void
    {
        SupportMessage::create([
            'support_request_id' => $request->getKey(),
            'author_role' => SupportAuthorRole::System,
            'author_user_id' => null,
            'body' => $body,
        ]);
    }
}
