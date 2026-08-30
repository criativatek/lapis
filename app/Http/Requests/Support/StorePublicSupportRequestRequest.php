<?php

namespace App\Http\Requests\Support;

use App\Models\SupportCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Um pedido aberto por quem não tem conta.
 *
 * AQUI O NOME E O EMAIL SÃO CAMPOS, porque não há de onde os tirar — e são a
 * única forma de responder. O email não é verificado: exigir verificação
 * transformaria «não consigo entrar na minha conta» num problema sem canal,
 * que é precisamente a pessoa que mais precisa deste formulário.
 *
 * SEM CONTEXTO TÉCNICO. Um visitante não está dentro da aplicação, e aceitar
 * uma rota vinda de fora seria aceitar texto arbitrário com aspecto de
 * diagnóstico.
 *
 * O QUE NÃO É PERSISTIDO: IP e User-Agent. O IP serve ao limitador de pedidos
 * da rota e morre aí — guardá-lo faria deste formulário um registo de visitas.
 */
class StorePublicSupportRequestRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'requester_name' => ['required', 'string', 'max:120'],
            'requester_email' => ['required', 'email', 'max:255'],
            'category' => ['required', Rule::enum(SupportCategory::class)],
            'subject' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'requester_name' => __('nome'),
            'requester_email' => __('email'),
            'category' => __('assunto'),
            'subject' => __('resumo'),
            'description' => __('descrição'),
        ];
    }
}
