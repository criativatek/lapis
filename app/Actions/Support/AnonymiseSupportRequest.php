<?php

namespace App\Actions\Support;

use App\Models\SupportRequest;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * ANONIMIZAÇÃO A SÉRIO — NULL é NULL, e as mensagens desaparecem.
 *
 * Passados 24 meses sobre `resolved_at` e sem suspensão em vigor, o que
 * identificava a pessoa deixa de existir. **Sem marcas de substituição**: nada
 * de «Pedido anonimizado», nada de `anonimizado-…@invalido.local`, nada de
 * «Conteúdo removido por retenção».
 *
 * Isto diverge de propósito de `AnonymiseClosedAccount`, que usa marcas porque
 * a linha do utilizador tem de continuar a satisfazer `NOT NULL` e a unicidade
 * do email — restrições que aqui não existem, porque as colunas nasceram
 * nullable exactamente para isto. Um placeholder é um valor: ocupa espaço numa
 * listagem, aparece numa exportação, e alguém acaba por o ler como se fosse um
 * facto sobre a pessoa (ADR-0011 §8).
 *
 * O QUE SOBREVIVE é o que serve estatística e não identifica ninguém:
 * `reference`, `category`, `source`, `status`, `app_version`, os carimbos
 * temporais, o `technical_code` — vocabulário fechado, onde um nome não se
 * consegue esconder — e os campos da suspensão **excepto a nota**, que é o
 * único deles onde um operador escreveu à mão.
 *
 * IDEMPOTENTE: `anonymized_at` faz a segunda passagem não fazer nada. Um
 * comando que corre duas vezes no mesmo dia não pode contar o mesmo pedido
 * duas vezes nem falhar por já não haver nada para apagar.
 */
class AnonymiseSupportRequest
{
    public function __construct(protected AuditLog $audit) {}

    public function execute(SupportRequest $request): SupportRequest
    {
        if ($request->isAnonymised()) {
            return $request;
        }

        return DB::transaction(function () use ($request): SupportRequest {
            // A conversa inteira. É a única cópia do que a pessoa escreveu, e é
            // suposto deixar de existir.
            $request->messages()->delete();

            // O estado técnico dos avisos. Guarda tipo, papel e contagens —
            // nada que responda a uma pergunta que o pedido anonimizado não
            // responda melhor —, por isso vai com o resto em vez de ficar a
            // apontar para uma conversa que já não existe.
            $request->deliveries()->delete();

            // AS IMAGENS, E OS FICHEIROS DELAS. Apagar a linha e deixar o
            // ficheiro no disco seria a pior das duas metades: a promessa de
            // eliminação passaria a ter uma excepção que ninguém vê, e uma
            // captura de ecrã do Lapispro é uma imagem de nomes de crianças.
            // O `delete()` do disco não levanta excepção (`'throw' => false`),
            // por isso um ficheiro já ausente não trava a transacção.
            foreach ($request->attachments as $attachment) {
                Storage::disk('local')->delete($attachment->disk_path);
            }

            $request->attachments()->delete();

            // O QUE SAIU PARA FORA. Expurgar um issue exportado é o melhor que a
            // API permite — reescrever e fechar — e o GitHub guarda o histórico
            // de edições, portanto isto reduz sem eliminar. É essa a diferença
            // que a ADR-0013 obriga a assumir antes de ligar a exportação, e a
            // razão de ela ser uma decisão caso a caso.
            //
            // Falhar aqui não trava a anonimização: o que este método promete é
            // que o Lapispro deixa de ter os dados, e essa parte cumpre-se.
            app(ExportIssueToGithub::class)->redact($request);

            $request->forceFill([
                'requester_name' => null,
                'requester_email' => null,
                'user_id' => null,
                'organization_id' => null,
                'subject' => null,
                'description' => null,
                'technical_reference' => null,
                'technical_route' => null,
                // Contexto recolhido sobre a sessão de quem escreveu, e por isso
                // do mesmo lado da linha que a rota: desaparece com ela.
                'client_context' => null,
                // O ÂMBITO do aceite vai; o FACTO de ter havido aceite fica. Saber
                // que alguém consentiu é o registo de um acto dela, como
                // `resolved_by`; saber que aquele aceite cobria duas imagens de um
                // ecrã concreto é informação sobre o que ela enviou.
                'consent_scope' => null,
                // Um apontador para conteúdo é conteúdo.
                'github_issue_number' => null,
                'github_issue_url' => null,
                // O único campo da suspensão que sai: é o que foi escrito à
                // mão. O motivo, as datas e as autorias ficam — são a prova de
                // que a excepção existiu, e não dizem nada sobre o titular.
                'retention_hold_note' => null,
                'anonymized_at' => Carbon::now(),
            ])->save();

            // Sem causer: isto não é acto de ninguém, é o prazo a cumprir-se.
            // E sem uma única propriedade que identifique — a referência
            // sobrevive na própria linha, e é por ela que se liga o rasto ao
            // pedido sem voltar a nomear a pessoa.
            $this->audit->recordPlatformWithoutCauser(
                'support.anonymised',
                'Pedido de suporte '.$request->reference.' anonimizado por retenção.',
                [
                    'reference' => $request->reference,
                    'category' => $request->category->value,
                    'months_retained' => (int) config('retention.support_resolved_months_retained'),
                ],
            );

            return $request;
        });
    }
}
