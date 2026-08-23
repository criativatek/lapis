<?php

namespace App\Support\Assessment;

use RuntimeException;

/**
 * Multi-row rules an instrument must satisfy (§12.3). They span rows, so they
 * cannot be CHECK constraints and live in the builder instead.
 */
class InstrumentValidationException extends RuntimeException
{
    public static function noItems(): self
    {
        return new self(__('O elemento de avaliação precisa de pelo menos uma questão ou critério.'));
    }

    /**
     * A code identifies a question inside its group, not across the whole
     * instrument — Oralidade/Q2 and Gramática/Q2 are two different questions.
     */
    public static function duplicateItemCodeInGroup(string $itemCode, ?string $groupLabel): self
    {
        if ($groupLabel === null || trim($groupLabel) === '') {
            return new self(__(
                'Já existe uma questão com o código :code neste elemento de avaliação.',
                ['code' => $itemCode],
            ));
        }

        return new self(__(
            'Já existe uma questão com o código :code no grupo :group.',
            ['code' => $itemCode, 'group' => $groupLabel],
        ));
    }

    public static function cannotRemoveGroupWithItems(string $groupLabel): self
    {
        if (trim($groupLabel) === '') {
            return new self(__('Não é possível remover um grupo que ainda tem questões. Mova ou elimine as questões primeiro.'));
        }

        return new self(__(
            'Não é possível remover o grupo :group porque ainda tem questões. Mova ou elimine as questões primeiro.',
            ['group' => $groupLabel],
        ));
    }

    public static function allocationsMustTotal100(string $itemCode, string $actual): self
    {
        return new self(__(
            'A distribuição por domínios da questão :code tem de somar 100%. Total atual: :total%.',
            ['code' => $itemCode, 'total' => $actual],
        ));
    }

    public static function duplicateDomainAllocation(string $itemCode): self
    {
        return new self(__(
            'Cada domínio só pode aparecer uma vez na distribuição da questão :code.',
            ['code' => $itemCode],
        ));
    }

    public static function pointsDoNotMatchTotal(string $itemTotal, string $declared): self
    {
        return new self(__(
            'A soma das cotações (:items) não corresponde à cotação total (:declared). '.
            'Ative a opção de bónus se for intencional.',
            ['items' => $itemTotal, 'declared' => $declared],
        ));
    }

    public static function cannotRemoveScoredItem(string $itemCode): self
    {
        return new self(__(
            'A questão :code já tem notas lançadas e não pode ser removida. Limpe as notas dessa questão primeiro.',
            ['code' => $itemCode],
        ));
    }

    public static function pointsPossibleBelowExistingScore(string $itemCode, string $minValue): self
    {
        return new self(__(
            'A cotação da questão :code não pode ser inferior a :min — já existe uma nota lançada com esse valor.',
            ['code' => $itemCode, 'min' => $minValue],
        ));
    }
}
