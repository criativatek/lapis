<?php

namespace App\Services\Reporting\Writing;

/**
 * The words this application uses, and the ones it refuses (§32, §33).
 *
 * A GLOSSARY IS NOT STYLE ADVICE HERE, IT IS A CORRECTNESS RULE. «Nota» and
 * «classificação» are not synonyms in Portuguese school documents: one is a mark
 * on a test, the other is the decision a teacher makes and publishes. A rewrite
 * that swaps them changes what the document claims happened, and it does it in
 * language that reads perfectly well — which is exactly the failure this whole
 * module is built to prevent.
 *
 * The pt-BR list is short and specific. A general instruction to «write European
 * Portuguese» is worth having, but the gerund constructions and the handful of
 * lexical items below are what actually leaks, and naming them is cheaper than
 * hoping.
 *
 * KEPT SMALL ON PURPOSE. Every line here is a line in a prompt, and a prompt
 * that lists two hundred rules is a prompt where the first ten stop mattering.
 */
class WritingGlossary
{
    /**
     * Terms whose replacement would change the meaning, not the register.
     *
     * @var array<string, string>
     */
    protected const PRESERVE = [
        'Média Ponderada' => 'o resultado do período, calculado com os pesos do perfil',
        'Média Ponderada Acumulada' => 'o resultado do ano até ao momento; nunca é uma média de médias',
        'classificação atribuída' => 'a decisão do professor, já tomada — não é uma nota nem uma proposta',
        'proposta de classificação' => 'o que o sistema calculou e ainda não foi decidido',
        'autoavaliação' => 'o que o aluno disse sobre si próprio; nunca uma afirmação do professor',
        'domínio' => 'uma área do perfil de avaliação; não é um tema nem um conteúdo',
        'avaliação contínua' => 'o processo, não um instrumento',
        'instrumento' => 'um elemento de avaliação concreto',
        'perfil de avaliação' => 'a configuração de domínios e pesos em vigor',
    ];

    /**
     * Substitutions a well-meaning model makes and must not.
     *
     * @var array<string, string>
     */
    protected const NEVER_SUBSTITUTE = [
        'classificação' => 'nota',
        'Média Ponderada' => 'média simples',
        'autoavaliação' => 'opinião',
        'domínio' => 'área, matéria, conteúdo ou tema',
        'registo' => 'ocorrência ou incidente',
    ];

    /**
     * pt-BR forms that turn up in generated Portuguese.
     *
     * @var list<string>
     */
    protected const EUROPEAN_PORTUGUESE = [
        'usa a próclise e a mesóclise portuguesas, não a ênclise brasileira',
        'não uses gerúndio para exprimir ação em curso («está a melhorar», nunca «está melhorando»)',
        'usa «facto», «contacto», «atividade», «letivo» e «objetivo» na grafia europeia',
        'usa «turma» e «aluno», nunca «classe» ou «estudante» neste contexto',
        'trata o leitor por «o professor» ou pela terceira pessoa, nunca por «você»',
    ];

    /** The glossary as it appears in the prompt. */
    public static function forPrompt(): string
    {
        $lines = ['TERMINOLOGIA DO LÁPIS — usa exatamente estes termos e não os substituas:'];

        foreach (self::PRESERVE as $term => $meaning) {
            $lines[] = '- «'.$term.'»: '.$meaning.'.';
        }

        $lines[] = '';
        $lines[] = 'NUNCA substituas:';

        foreach (self::NEVER_SUBSTITUTE as $correct => $wrong) {
            $lines[] = '- «'.$correct.'» por «'.$wrong.'».';
        }

        $lines[] = '';
        $lines[] = 'PORTUGUÊS EUROPEU:';

        foreach (self::EUROPEAN_PORTUGUESE as $rule) {
            $lines[] = '- '.$rule.'.';
        }

        return implode("\n", $lines);
    }

    /**
     * Terms whose exact wording has to survive a rewrite.
     *
     * ONE ENTRY, AND THAT IS DELIBERATE. «Média Ponderada» is the NAME of a
     * calculation, not a description of it: a figure introduced as «a média» has
     * stopped saying which of the two readings it is, and §71 of the narrative
     * revision exists precisely because that ambiguity appears the moment a year
     * has more than one period.
     *
     * Everything else worth preserving — the self-assessment, the proposal, the
     * absence of data — is checked by RewriteGuard as a GROUP of alternative
     * phrasings, so a rewrite is free to say it differently. Listing those here
     * as well would demand the exact word back and refuse "propõe-se" as a
     * rewrite of "foi proposta", which is a good rewrite.
     *
     * @return list<string>
     */
    public static function loadBearingTerms(): array
    {
        return ['média ponderada'];
    }
}
