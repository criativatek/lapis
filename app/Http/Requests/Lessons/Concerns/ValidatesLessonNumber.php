<?php

namespace App\Http\Requests\Lessons\Concerns;

use App\Actions\Lessons\NumberChange;
use Closure;

/**
 * As regras do número da lição indicado pelo professor (0.169.0), num só sítio
 * para o sumário completo, o sumário de cartão e a pré-visualização.
 *
 * O início é um inteiro de 1 a 9999; o fim, se existir, não é anterior ao início
 * e a aula corresponde a 10 lições, no máximo. As mensagens são escritas aqui
 * porque o ecrã as mostra tal e qual junto ao campo.
 */
trait ValidatesLessonNumber
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function lessonNumberRules(string $startKey, string $endKey): array
    {
        return [
            $startKey => ['required_with:'.$endKey, 'integer', 'min:1', 'max:9999'],
            $endKey => [
                'nullable', 'integer', 'min:1', 'max:9999', 'gte:'.$startKey,
                function (string $attribute, mixed $value, Closure $fail) use ($startKey): void {
                    $start = $this->input($startKey);

                    if (is_numeric($start) && is_numeric($value) && (int) $value - (int) $start > 9) {
                        $fail(__('Uma aula pode corresponder, no máximo, a 10 lições.'));
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function lessonNumberMessages(string $startKey, string $endKey): array
    {
        $integer = __('O número da lição tem de ser um número inteiro positivo.');

        return [
            $startKey.'.required_with' => __('Indique o número da lição.'),
            $startKey.'.integer' => $integer,
            $startKey.'.min' => $integer,
            $startKey.'.max' => $integer,
            $endKey.'.integer' => $integer,
            $endKey.'.min' => $integer,
            $endKey.'.max' => $integer,
            $endKey.'.gte' => __('O fim do intervalo não pode ser anterior ao início.'),
        ];
    }

    /**
     * Normaliza o texto vindo do formulário: um campo vazio é «sem valor», e não
     * zero nem um texto que falhe a validação do inteiro.
     *
     * @param  list<string>  $keys
     */
    protected function blankNumberFieldsToNull(array $keys): void
    {
        foreach ($keys as $key) {
            if ($this->has($key) && is_string($this->input($key)) && trim($this->string($key)->toString()) === '') {
                $this->merge([$key => null]);
            }
        }
    }

    /**
     * O pedido de numeração do sumário, ou NULL se a gravação não trouxe
     * nenhuma chave `lesson_number*` — o caso de todos os chamadores antigos
     * (sequências, importação, cartão sem alteração), em que a numeração não se
     * toca de todo.
     */
    public function lessonNumberChange(): ?NumberChange
    {
        if ($this->boolean('lesson_number_automatic')) {
            return NumberChange::automatic();
        }

        $start = $this->validated('lesson_number');

        if (! is_numeric($start)) {
            return null;
        }

        $end = $this->validated('lesson_number_end');

        return NumberChange::manual((int) $start, is_numeric($end) ? (int) $end : null);
    }

    public function lessonNumberConfirmation(): ?string
    {
        $value = $this->validated('lesson_number_confirmation');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
