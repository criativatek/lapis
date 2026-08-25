<?php

namespace App\Http\Requests\Calendar;

use Illuminate\Foundation\Http\FormRequest;

/**
 * As datas que o professor marcou no diálogo «Sugerir feriados nacionais» — e
 * MAIS NADA.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * O PEDIDO SÓ TRAZ DATAS, E É DE PROPÓSITO QUE NÃO TRAZ TÍTULOS.
 *
 * Uma exceção escrita por este caminho fica com `source = suggested`, e essa
 * palavra tem de significar exatamente uma coisa: «isto veio da lista de feriados
 * nacionais deste país». Se o título viesse no corpo do pedido, qualquer pedido
 * podia escrever «Aniversário do Manuel» com a proveniência de um feriado oficial,
 * e a coluna deixava de responder à pergunta que existe para responder.
 *
 * Por isso o servidor volta a pedir a lista ao provider e é DELE que tira o nome —
 * o navegador só diz QUAIS das datas propostas é que o professor marcou. Uma data
 * que não esteja na lista não é uma linha a saltar: é um pedido que este ecrã não
 * podia ter produzido, e recusa-se inteiro (ver o controlador).
 *
 * A AUTORIZAÇÃO NÃO ESTÁ AQUI, exatamente como não está em
 * AcademicCalendarExceptionRequest: é `Gate::authorize('update', $academicYear)`
 * no controlador, a mesma superfície dos períodos e das exceções escritas à mão.
 * Não há política nova para isto.
 */
class NationalHolidaySuggestionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // O teto é generoso e mesmo assim muito acima do possível: um ano
            // letivo português inteiro tem treze feriados nacionais, e um ecrã que
            // ofereça cinquenta linhas já não é este ecrã.
            'dates' => ['required', 'array', 'min:1', 'max:50'],
            'dates.*' => ['required', 'date_format:Y-m-d', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dates.required' => __('Escolha pelo menos um feriado para acrescentar.'),
            'dates.*.date_format' => __('Indique uma data válida.'),
            'dates.*.distinct' => __('O mesmo feriado foi enviado duas vezes.'),
        ];
    }

    /**
     * @return list<string>
     */
    public function dates(): array
    {
        /** @var list<string> */
        return array_values($this->validated()['dates']);
    }
}
