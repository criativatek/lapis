<?php

namespace App\Mail;

use App\Models\SupportNotificationType;
use App\Models\SupportRecipientRole;
use App\Models\SupportRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * TODOS OS AVISOS DA CENTRAL, NUM SÓ MAILABLE — porque todos obedecem à mesma
 * regra e ter quatro classes seria ter quatro sítios onde a quebrar.
 *
 * A REGRA: **nunca o assunto escrito pela pessoa, nunca a descrição, nunca o
 * corpo de uma mensagem.** Um email atravessa servidores que não são nossos e
 * fica em caixas de entrada que não controlamos; um pedido de suporte contém,
 * quase sempre, mais dados pessoais do que qualquer outro texto que um
 * professor escreve — «o aluno X não aparece na turma Y». Por isso o que sai
 * daqui é a referência, a categoria, o estado, e um caminho para o sítio onde o
 * conteúdo está protegido.
 *
 * ASSUNTOS FIXOS, e é por serem fixos que são seguros: nada do que a pessoa
 * escreveu chega à linha de assunto, que é a parte do email que aparece em
 * notificações de telemóvel e em pré-visualizações.
 *
 * O «PEDIDO:» DA CONFIRMAÇÃO É A CATEGORIA, NÃO O RESUMO. A confirmação de
 * receção mostra uma linha que identifica o pedido, e a tentação óbvia é usar
 * o `subject` — mas o campo que o formulário chama «Resumo» é texto livre, e é
 * exactamente onde é mais fácil escrever «o aluno João não aparece na turma
 * 5.ºB» sem pensar. `SupportCategory` é vocabulário fechado, diz à pessoa qual
 * dos seus pedidos é este, e não pode conter o nome de ninguém. O resumo
 * continua a existir onde está protegido: no pedido, dentro da aplicação.
 *
 * `Reply-To` É `lapis.support.inbox` — configuração operacional, nunca escrita
 * à mão aqui. O `From` continua o da instalação: mudá-lo partiria o SPF que
 * autoriza o relay actual, e uma mensagem que não chega é pior do que uma
 * mensagem com o remetente genérico.
 *
 * SEM `ShouldQueue`, deliberadamente. Não há worker em produção; ver
 * `SupportNotifier`.
 */
class SupportNotificationMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public SupportRequest $request,
        public SupportNotificationType $type,
    ) {}

    public function build(): self
    {
        $paraEquipa = $this->type->recipientRole() === SupportRecipientRole::SupportTeam;

        return $this
            ->subject($this->fixedSubject())
            ->replyTo((string) config('lapis.support.inbox'))
            ->view('emails.support-notification', [
                'reference' => $this->request->reference,
                'categoryLabel' => $this->request->category->label(),
                'statusLabel' => $this->request->status->label(),
                'type' => $this->type,
                // O CTA existe apenas para quem tem conta e pode abrir o
                // pedido. Um visitante não tem portal (ADR-0011 §3) e por isso
                // não recebe caminho nenhum — mandá-lo para uma página de
                // login que não lhe serve de nada seria pior do que não o
                // mandar a lado nenhum.
                'url' => $this->ctaUrl($paraEquipa),
                'forTeam' => $paraEquipa,
                // O primeiro nome de quem pediu, para a confirmação poder
                // cumprimentar alguém. É o dado da PRÓPRIA pessoa a seguir
                // para a caixa de correio DELA: a regra que este email cumpre
                // é sobre o que foi escrito no pedido — que pode falar de
                // alunos —, não sobre reconhecer quem o escreveu.
                'firstName' => $paraEquipa ? null : $this->request->requesterFirstName(),
                // Ter conta muda o que se pode PROMETER. A quem a tem, o
                // pedido está lá para acompanhar; a um visitante, a equipa
                // responde por email e mais nada — dizer-lhe o contrário
                // mandava-o procurar um ecrã que não existe (ADR-0011 §3).
                'authenticated' => $this->request->user_id !== null,
            ]);
    }

    /**
     * Os assuntos, fixos e sem uma única palavra escrita por quem pediu.
     *
     * A CONFIRMAÇÃO DIZ O QUE É logo na linha de assunto — «Recebemos o seu
     * pedido» —, porque é a parte que aparece na notificação do telemóvel e em
     * pré-visualizações, e é aí que a pessoa decide se ficou tratada ou se tem
     * de voltar a escrever. A referência vem a seguir, para poder ser
     * procurada na caixa de correio.
     */
    protected function fixedSubject(): string
    {
        return match ($this->type) {
            SupportNotificationType::TeamNewRequest => __('Novo pedido de suporte :reference', [
                'reference' => $this->request->reference,
            ]),
            SupportNotificationType::RequestReceived => __('Recebemos o seu pedido de suporte — :reference', [
                'reference' => $this->request->reference,
            ]),
            default => __('Pedido de suporte :reference recebido', [
                'reference' => $this->request->reference,
            ]),
        };
    }

    /**
     * Para onde o botão aponta — ou NULL quando não deve haver botão.
     *
     * A equipa vai para o backoffice; quem tem conta vai para o seu pedido; um
     * visitante não vai a lado nenhum.
     */
    protected function ctaUrl(bool $paraEquipa): ?string
    {
        if ($paraEquipa) {
            return url('/admin/support/'.$this->request->ulid);
        }

        return $this->request->user_id === null
            ? null
            : url('/support/'.$this->request->ulid);
    }
}
