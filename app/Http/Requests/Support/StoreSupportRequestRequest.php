<?php

namespace App\Http\Requests\Support;

use App\Models\SupportCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Um pedido aberto por quem tem sessão iniciada.
 *
 * NÃO ACEITA NOME NEM EMAIL. Vêm da conta, no servidor: aceitá-los do corpo
 * deixaria qualquer pessoa abrir um pedido em nome de outra e receber a
 * resposta no seu próprio endereço.
 *
 * O CONTEXTO TÉCNICO É OPCIONAL E LIMITADO À ROTA E A UMA REFERÊNCIA. Nada de
 * ficheiros, nada de dados pedagógicos, nada de stack traces — o que o ecrã
 * sabe dizer sobre onde estava, e mais nada. `technical_code` não está aqui de
 * todo: é classificação interna, e quem classifica é um operador.
 *
 * A OBRIGATORIEDADE VIVE AQUI e não no esquema. As colunas são nullable porque
 * a retenção as esvazia aos 24 meses; exigir `NOT NULL` na base de dados
 * tornaria a promessa de eliminação impossível de cumprir.
 */
class StoreSupportRequestRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::enum(SupportCategory::class)],
            'subject' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:5000'],
            'technical_reference' => ['nullable', 'string', 'max:120'],
            'technical_route' => ['nullable', 'string', 'max:200'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'category' => __('assunto'),
            'subject' => __('resumo'),
            'description' => __('descrição'),
        ];
    }
}
