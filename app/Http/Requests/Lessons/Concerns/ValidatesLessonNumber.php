<?php

namespace App\Http\Requests\Lessons\Concerns;

use App\Actions\Lessons\NumberChange;

/**
 * As regras do número da lição indicado pelo professor (0.169.0), num só sítio
 * para o sumário completo, o sumário de cartão e a pré-visualização.
 *
 * O número é um inteiro de 1 a 9999. As mensagens são escritas aqui porque o
 * ecrã as mostra tal e qual junto ao campo.
 *
 * UMA AULA, UM NÚMERO (0.172.0, ADR-0017). A chave do fim do intervalo
 * (`lesson_number_end` / `end`) é LEGADO: aceita-se vazia — é o que um ecrã
 * anterior à 0.172.0 envia sempre que a caixa «mais do que uma lição» está
 * desmarcada —, e qualquer valor é RECUSADO, com a mensagem junto ao campo do
 * fim. Nunca se ignora em silêncio: quem a marcou num separador antigo fica a
 * saber que a gravação não fez o que pediu.
 */
trait ValidatesLessonNumber
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function lessonNumberRules(string $startKey, string $legacyEndKey): array
    {
        return [
            $startKey => ['integer', 'min:1', 'max:9999'],
            $legacyEndKey => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function lessonNumberMessages(string $startKey, string $legacyEndKey): array
    {
        $integer = __('O número da lição tem de ser um número inteiro positivo.');

        return [
            $startKey.'.integer' => $integer,
            $startKey.'.min' => $integer,
            $startKey.'.max' => $integer,
            $legacyEndKey.'.prohibited' => __('Uma aula corresponde a uma só lição. Indique apenas o número da lição.'),
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

        return is_numeric($start) ? NumberChange::manual((int) $start) : null;
    }

    public function lessonNumberConfirmation(): ?string
    {
        $value = $this->validated('lesson_number_confirmation');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
