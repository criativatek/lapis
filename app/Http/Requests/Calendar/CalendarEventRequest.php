<?php

namespace App\Http\Requests\Calendar;

use App\Models\CalendarEventType;
use App\Models\SchoolClass;
use App\Models\User;
use App\Rules\BelongsToCurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * O que é preciso para guardar um acontecimento — na criação e na edição, com
 * as mesmas regras, porque são a mesma coisa escrita duas vezes de formas
 * diferentes é que dá dois comportamentos diferentes.
 *
 * `ends_on` É OPCIONAL AQUI e obrigatório na tabela: a ausência significa «o
 * mesmo dia», e é SaveCalendarEvent que a escreve. Ver o docblock da migração.
 *
 * `starts_at`/`ends_at` usam `date_format:H:i`, exatamente a convenção que
 * RecurringLessonSlotRequest já fixou para horas do dia neste projeto — e não
 * a regra `date`, que aceitaria alegremente uma data inteira num campo de hora.
 */
class CalendarEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(CalendarEventType::values())],
            // O mesmo limite que InstrumentRequest já usa para o título de uma
            // avaliação: as duas coisas aparecem lado a lado na mesma célula
            // do calendário e não há razão para uma poder ser mais longa.
            'title' => ['required', 'string', 'max:200'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => $this->endsOnRules(),
            'starts_at' => ['nullable', 'date_format:H:i'],
            'ends_at' => $this->endsAtRules(),
            'description' => ['nullable', 'string', 'max:2000'],
            'school_class_ulids' => ['nullable', 'array'],
            'school_class_ulids.*' => [
                'string',
                new BelongsToCurrentOrganization(SchoolClass::class, 'ulid'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ends_on.after_or_equal' => __('A data de fim não pode ser anterior à data de início.'),
            'ends_at.prohibited' => __('Indica também a hora de início.'),
            'ends_at.after' => __('A hora de fim tem de ser depois da hora de início.'),
        ];
    }

    /**
     * A turma tem de ser DESTE professor, e não apenas desta organização.
     *
     * BelongsToCurrentOrganization acima já barra a turma de outra organização;
     * o que falta é a turma de um colega, que é perfeitamente real e da mesma
     * organização e mesmo assim não é para aqui. A pergunta «que turmas é que
     * este professor leciona» tem uma só resposta neste projeto —
     * SchoolClass::scopeTaughtBy — e é essa que se faz, e não uma segunda
     * versão dela escrita à mão que pudesse vir a discordar.
     *
     * REJEITADA, e não descartada em silêncio: aceitar o pedido a fingir que
     * correu bem, deixando a turma de fora, é a forma mais rápida de o professor
     * ficar convencido de que anexou uma turma que na verdade não anexou.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $submitted = $this->input('school_class_ulids');

            if (! is_array($submitted) || $submitted === []) {
                return;
            }

            /** @var User $user */
            $user = $this->user();

            $mine = SchoolClass::query()
                ->taughtBy($user)
                ->pluck('ulid')
                ->all();

            foreach ($submitted as $index => $ulid) {
                if (! in_array($ulid, $mine, true)) {
                    $validator->errors()->add(
                        "school_class_ulids.{$index}",
                        __('Só podes associar turmas que lecionas.'),
                    );
                }
            }
        });
    }

    /**
     * @return list<string>
     */
    private function endsOnRules(): array
    {
        $rules = ['nullable', 'date_format:Y-m-d'];

        // Só se compara com uma data de início que exista: sem esta guarda, um
        // pedido sem `starts_on` acusaria dois erros pela mesma falta.
        if ($this->filled('starts_on')) {
            $rules[] = 'after_or_equal:starts_on';
        }

        return $rules;
    }

    /**
     * @return list<string>
     */
    private function endsAtRules(): array
    {
        $rules = ['nullable', 'date_format:H:i'];

        if ($this->filled('starts_at')) {
            // Num acontecimento de um só dia isto é a comparação óbvia. Num
            // acontecimento de vários dias, é a consistência interna do par de
            // horas — que é tudo o que o produto especifica, e chega: inventar
            // aqui semântica de «hora de fim no último dia» seria decidir, sem
            // ninguém ter pedido, coisas que a interface não pergunta.
            $rules[] = 'after:starts_at';
        } else {
            // Uma hora de fim sem hora de início não é um acontecimento com
            // metade das horas: é um pedido malformado.
            $rules[] = 'prohibited';
        }

        return $rules;
    }
}
