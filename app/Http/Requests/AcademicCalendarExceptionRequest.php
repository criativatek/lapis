<?php

namespace App\Http\Requests;

use App\Models\AcademicCalendarExceptionType;
use App\Models\AcademicYear;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * UMA exceção letiva — um feriado, uma interrupção letiva ou um dia não letivo
 * — validada sozinha, no seu próprio pedido.
 *
 * A DIFERENÇA REAL FACE ÀS ANTIGAS REGRAS `exceptions.*` DE AcademicYearRequest,
 * e a razão de esta classe existir: ali uma exceção era uma linha de um array
 * submetido com o ano inteiro, e por isso os erros chegavam com chaves como
 * `exceptions.4.title` — caminhos que o formulário tinha de saber ler e que
 * nenhuma pessoa alguma vez escreveria. Aqui os campos chamam-se `title`,
 * `starts_on`, `ends_on`, e um erro é sobre O campo, porque só há uma exceção
 * neste pedido. Gravar uma exceção passou a ser um gesto com o seu próprio
 * botão, e isto é o pedido que esse botão faz.
 *
 * A AUTORIZAÇÃO NÃO ESTÁ AQUI, e é deliberado: é exatamente a dos períodos —
 * `Gate::authorize('update', $academicYear)`, no controlador — porque quem pode
 * reformar a estrutura do ano é quem AcademicYearPolicy diz, e não há (nem
 * passou a haver) uma segunda política para isto.
 *
 * `source` NÃO SE VALIDA PORQUE NÃO SE ACEITA: a proveniência é escrita pelo
 * servidor e nunca vem do cliente — senão um formulário podia declarar-se
 * «importado» e a coluna deixava de ser uma resposta honesta à pergunta que
 * existe para responder.
 */
class AcademicCalendarExceptionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `Rule::in(...::values())` e não `Rule::enum(...)`: uma regra-objeto
            // traz consigo a sua própria mensagem («O valor selecionado em tipo
            // é inválido») e o `messages()` aqui em baixo não lhe chega. É a
            // mesma escolha, e pela mesma razão, que CalendarEventRequest já faz
            // ao seu próprio `type` — e a lista continua a sair do enum, que é o
            // que impede a página e o servidor de discordarem sobre ela.
            'type' => ['required', Rule::in(AcademicCalendarExceptionType::values())],
            // O mesmo limite que o título de um acontecimento e de uma
            // avaliação: as três aparecem lado a lado no mesmo calendário.
            'title' => ['required', 'string', 'max:200'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            // `after_or_equal`, E NÃO `after` — a diferença real face aos
            // períodos: um período tem de durar mais do que um dia, mas uma
            // exceção de um dia só é o caso MAIS comum que existe (um feriado).
            // É a mesma comparação que a CHECK da tabela faz.
            'ends_on' => $this->endsOnRules(),
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Uma observação em branco é a AUSÊNCIA de observação, e escreve-se null —
     * não uma string vazia que o leitor depois teria de saber tratar como se
     * fosse null. Era o que exceptionAttributes() fazia no serviço que este
     * pedido substituiu, e continua a fazer-se uma vez só, aqui.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('note') === '') {
            $this->merge(['note' => null]);
        }
    }

    /**
     * As mensagens, escritas para quem as lê. O `InputError` do formulário
     * imprime a string que chegar, tal e qual — pelo que a clareza desta página
     * é inteiramente o que está escrito aqui.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => __('Escolha o tipo: feriado, interrupção letiva ou dia não letivo.'),
            'type.in' => __('Escolha o tipo: feriado, interrupção letiva ou dia não letivo.'),
            'title.required' => __('A designação é obrigatória.'),
            'title.max' => __('A designação não pode ter mais de 200 caracteres.'),
            'starts_on.required' => __('Indique a data de início.'),
            'starts_on.date_format' => __('Indique uma data de início válida.'),
            'ends_on.required' => __('Indique a data de fim. Num feriado de um dia é igual à data de início.'),
            'ends_on.date_format' => __('Indique uma data de fim válida.'),
            'ends_on.after_or_equal' => __('Indique uma data de fim igual ou posterior à data de início.'),
            'note.max' => __('A observação não pode ter mais de 2000 caracteres.'),
        ];
    }

    /**
     * O nome de cada campo em português, para as poucas mensagens que a lista
     * acima não cobre nominalmente («o campo starts_on» nunca deve chegar a um
     * ecrã).
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'type' => __('tipo'),
            'title' => __('designação'),
            'starts_on' => __('data de início'),
            'ends_on' => __('data de fim'),
            'note' => __('observação'),
        ];
    }

    /**
     * DENTRO DO ANO LETIVO, verificado no servidor e não só no navegador.
     *
     * Os campos de data levam `min`/`max` na própria página, e isso é uma
     * gentileza — não a guarda: um `min` de HTML é uma palavra que o cliente diz
     * a si próprio, e qualquer pedido a pode ignorar. Esta é a mesma verificação
     * que AcademicYearRequest já faz aos períodos («o período tem de estar
     * dentro do ano letivo»), adaptada a UM registo em vez de um array — e por
     * isso escrita contra as datas do ano que já está gravado, e não contra
     * datas que viessem no mesmo pedido.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $year = $this->route('academic_year');

            if (! $year instanceof AcademicYear) {
                return;
            }

            $yearStart = $year->starts_on->toDateString();
            $yearEnd = $year->ends_on->toDateString();
            $window = __('O ano letivo vai de :from a :to.', [
                'from' => $year->starts_on->format('d/m/Y'),
                'to' => $year->ends_on->format('d/m/Y'),
            ]);

            $startsOn = $this->input('starts_on');
            $endsOn = $this->input('ends_on');

            // As datas comparam-se como texto porque são «Y-m-d» canónicas: a
            // ordem lexicográfica É a ordem cronológica, e é a mesma comparação
            // que os períodos já fazem umas linhas acima de onde isto vivia.
            if (is_string($startsOn) && $startsOn !== '' && $startsOn < $yearStart) {
                $validator->errors()->add(
                    'starts_on',
                    __('A data de início tem de estar dentro do ano letivo. :window', ['window' => $window]),
                );
            }

            if (is_string($endsOn) && $endsOn !== '' && $endsOn > $yearEnd) {
                $validator->errors()->add(
                    'ends_on',
                    __('A data de fim tem de estar dentro do ano letivo. :window', ['window' => $window]),
                );
            }
        });
    }

    /**
     * Só se compara com uma data de início que exista: sem esta guarda, um
     * pedido sem `starts_on` acusaria dois erros pela mesma falta — a mesma
     * disciplina que CalendarEventRequest já usa para o seu próprio par.
     *
     * @return list<string>
     */
    private function endsOnRules(): array
    {
        $rules = ['required', 'date_format:Y-m-d'];

        if ($this->filled('starts_on')) {
            $rules[] = 'after_or_equal:starts_on';
        }

        return $rules;
    }
}
