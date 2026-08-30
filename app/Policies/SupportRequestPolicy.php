<?php

namespace App\Policies;

use App\Models\SupportRequest;
use App\Models\User;

/**
 * QUEM VÊ UM PEDIDO DE SUPORTE — e a razão de `organization_id` não contar.
 *
 * Esta é a policy mais restritiva da aplicação, e de propósito. Todas as
 * outras assentam no global scope de organização: quem está dentro do
 * inquilino vê. Aqui não há global scope nenhum (o modelo está fora da
 * tenancy) e a regra é **uma pessoa, um pedido**:
 *
 *  - quem abriu vê o que abriu;
 *  - **um colega da mesma organização NÃO vê**, e o administrador
 *    institucional também não. Um pedido de suporte é frequentemente a pessoa
 *    a dizer que não consegue trabalhar, ou a relatar um erro com os alunos de
 *    uma turma — e o chefe dela não é destinatário disso;
 *  - `organization_id` é CONTEXTO para quem responde. É a coluna que mais se
 *    parece com tenancy neste domínio e é precisamente a que não autoriza nada;
 *  - **um guest não vê**. Não há portal, não há URL assinada, e a referência
 *    `SUP-XXXXXX` não é uma credencial — seis caracteres legíveis não seguram
 *    um segredo, e nenhum método aqui a aceita (ADR-0011 §3).
 *
 * O PLATFORM-ADMIN NÃO ESTÁ AQUI. Quem opera a plataforma chega aos pedidos
 * pelo backoffice, atrás do middleware `platform-admin`, que é a fronteira
 * única desse acesso em toda a aplicação. Dar-lhe um ramo `before()` nesta
 * policy criaria uma segunda porta para a mesma sala — e duas portas divergem.
 */
class SupportRequestPolicy
{
    public function view(User $user, SupportRequest $request): bool
    {
        return $this->belongsTo($user, $request);
    }

    /**
     * Responder ao próprio pedido — inclusive um já resolvido, que é o que o
     * reabre (ADR-0011 §12).
     *
     * Um pedido anonimizado não aceita mais nada: já não há conversa, e o
     * `user_id` a NULL fá-lo-ia falhar na verificação abaixo de qualquer forma.
     * A verificação explícita existe para a recusa ser legível.
     */
    public function reply(User $user, SupportRequest $request): bool
    {
        return $this->belongsTo($user, $request) && ! $request->isAnonymised();
    }

    /**
     * A dona da linha é a conta, não a organização.
     *
     * `user_id` nulo — um pedido de visitante, ou um já anonimizado — nunca
     * pertence a ninguém, e a comparação estrita garante que um utilizador
     * recém-criado não herda o pedido de um `user_id` que já não existe.
     */
    protected function belongsTo(User $user, SupportRequest $request): bool
    {
        return $request->user_id !== null && $request->user_id === $user->getKey();
    }
}
