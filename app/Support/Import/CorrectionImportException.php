<?php

namespace App\Support\Import;

use RuntimeException;

/**
 * A refusal to import, phrased for the teacher.
 *
 * Every message here explains what is missing and what to do; none of them
 * quotes the file, names a student or repeats a mark. These exceptions travel
 * into logs and error reports, and the files behind them are full of children's
 * names (§26).
 */
class CorrectionImportException extends RuntimeException
{
    public static function notEntitled(): self
    {
        return new self(__('A importação de resultados de outras plataformas está disponível no LÁPIS Pro.'));
    }

    public static function notReady(): self
    {
        return new self(__('Esta importação ainda não está pronta para ser confirmada. Reveja os passos anteriores.'));
    }

    public static function alreadyImported(): self
    {
        return new self(__('Esta importação já foi confirmada.'));
    }

    public static function nothingParsed(): self
    {
        return new self(__('Não há nada lido deste ficheiro para importar.'));
    }

    public static function blockedByErrors(int $count): self
    {
        return new self(trans_choice(
            '{1}Há 1 problema por resolver antes de importar.|[2,*]Há :count problemas por resolver antes de importar.',
            $count,
            ['count' => $count],
        ));
    }

    public static function missingPoints(int $count): self
    {
        return new self(trans_choice(
            '{1}Falta definir a cotação de 1 pergunta.|[2,*]Falta definir a cotação de :count perguntas.',
            $count,
            ['count' => $count],
        ));
    }

    public static function studentsNotDecided(int $count): self
    {
        return new self(trans_choice(
            '{1}Falta decidir a correspondência de 1 aluno do ficheiro.|[2,*]Falta decidir a correspondência de :count alunos do ficheiro.',
            $count,
            ['count' => $count],
        ));
    }

    public static function itemsNotMapped(int $count): self
    {
        return new self(trans_choice(
            '{1}Falta indicar a que pergunta do instrumento corresponde 1 pergunta do ficheiro.|[2,*]Faltam indicar a que perguntas do instrumento correspondem :count perguntas do ficheiro.',
            $count,
            ['count' => $count],
        ));
    }

    public static function instrumentNotEligible(string $statusLabel): self
    {
        return new self(__('Não é possível importar para um instrumento no estado «:estado». Reabra a correção primeiro, se for esse o caso.', ['estado' => $statusLabel]));
    }

    public static function instrumentNotChosen(): self
    {
        return new self(__('Escolha o instrumento a que os resultados se destinam.'));
    }

    public static function instrumentNotInClass(): self
    {
        return new self(__('O instrumento escolhido não pertence a esta turma.'));
    }

    /**
     * Only ever raised for an instrument that counts. A global result allocated
     * to no domain would enter no domain's calculation at all — legitimate in
     * the model, but almost never what a teacher importing a classification
     * means, and silent if it were allowed through (§6).
     */
    public static function overallDomainNotChosen(): self
    {
        return new self(__('Selecione o domínio avaliado. É ele que diz para onde conta este resultado.'));
    }

    public static function overallTargetNotChosen(): self
    {
        return new self(__('Indique qual a pergunta da avaliação que recebe o resultado global.'));
    }

    /**
     * The teacher asked to import a classification the platform never produced.
     * Refusing beats importing a column of dashes as a column of zeros.
     */
    public static function noOverallResults(): self
    {
        return new self(__('Este ficheiro não traz uma classificação por aluno. Importe o detalhe das perguntas.'));
    }
}
