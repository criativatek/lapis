<?php

namespace App\Services\Ai\Gateway;

use App\Services\Progress\Ai\FollowupSynthesisParser;
use App\Services\Progress\Ai\FollowupSynthesisPrompt;

/**
 * The material the backoffice's «Teste de capacidade» sends, and the check it
 * runs on what comes back.
 *
 * WHY A CAPABILITY TEST EXISTS BESIDE A CONNECTION TEST. «Testar ligação» asks
 * the engine for the word «OK». It is a good test of a credential, a base URL
 * and a route out of the building, and it is worth keeping exactly as it is —
 * but it answers a question nobody was asking. A model can return «OK» in three
 * tokens and still be unable to produce one usable answer in this product,
 * because the work this product asks for is SIX LABELLED SECTIONS under a
 * two-page instruction, and that is a different demand on the same model in
 * every respect that matters: the length of the system prompt, the size of the
 * answer, the structure the parser requires, and — on the Gemini 2.5 line — how
 * much of the token budget is spent thinking before a single word is written.
 * The connection test stayed green through the entire followup-synthesis
 * outage. That is the gap this class closes.
 *
 * IT SENDS THE REAL INSTRUCTION. Not a shortened one, not a paraphrase:
 * `FollowupSynthesisPrompt::text()`, the same string the feature sends, so that
 * the prompt's own length is part of what is being measured. A probe built on a
 * smaller prompt would pass under a budget the real prompt exhausts, which is
 * the precise failure it is supposed to catch.
 *
 * AND IT CHECKS WITH THE REAL PARSER. `FollowupSynthesisParser::parse()` is the
 * verdict — including its rule that an answer with nothing in POSITIVOS is
 * refused. «Utilizável» means what it means everywhere else in the product, or
 * the test is measuring something the product does not use.
 *
 * NOBODY'S DATA IS IN IT. The record below is invented, fixed, and checked into
 * this repository: no student, no teacher, no organization, no class. It is the
 * same seventeen lines on every installation, it is written in the shape
 * `FollowupContext` produces (`Rótulo: valor`, one per line, «Aluno A» for the
 * subject), and it contains nothing that would need pseudonymising — which is
 * why it can be sent from a screen that has no tenant at all. CLAUDE.md §31
 * forbids sending identifying student data to an external service; this is the
 * version of that sentence that has no student in it to begin with.
 */
class AiCapabilityProbe
{
    /** Bumped with the fixture below, so a usage row stays interpretable. */
    public const VERSION = 'lapis-capability-probe/1';

    /** The instruction the probe sends — the feature's own, unmodified. */
    public static function instruction(): string
    {
        return FollowupSynthesisPrompt::text();
    }

    /**
     * A synthetic student record, in the shape `FollowupContext` emits.
     *
     * DIMENSIONED LIKE A REAL ONE, because a probe on three lines would prove
     * nothing about a prompt that has to hold six sections together. The
     * numbers are plausible and internally consistent — a mid-year record with
     * a result, some registos, one intervention under way and a previous period
     * to compare against — so the model has something to say in every section
     * the parser requires, including MUDOU.
     */
    public static function content(): string
    {
        return implode("\n", [
            'Aluno: Aluno A',
            'Disciplina: Matemática',
            'Ano de escolaridade: 7',
            'Período em análise: 2.º período',
            'Período anterior: 1.º período',
            'Resultado do período: 3 (escala 1 a 5)',
            'Resultado do período anterior: 3 (escala 1 a 5)',
            'Domínios com resultado: 4 de 5',
            'Domínio Números e operações: 4 (escala 1 a 5)',
            'Domínio Geometria e medida: 2 (escala 1 a 5)',
            'Domínio Álgebra: 3 (escala 1 a 5)',
            'Domínio Organização e tratamento de dados: 3 (escala 1 a 5)',
            'Domínio Pensamento computacional: sem resultado no período',
            'Registos pedagógicos no período: 6',
            'Registos por categoria: participação 3, trabalho de casa 2, comportamento 1',
            'Trabalhos de casa por realizar registados: 2',
            'Intervenções ativas: 1',
            'Intervenção 1: categoria apoio ao estudo, estado em curso, iniciada no 1.º período, revisão pendente',
            'Autoavaliação registada: o aluno classificou o seu desempenho como muito bom',
            'Assiduidade: sem faltas registadas no período',
            'Sinais positivos apurados pelo sistema: 2',
            'Sinais de atenção apurados pelo sistema: 2',
        ]);
    }

    /**
     * Whether the answer is one this product could actually have used.
     *
     * The parser's own verdict, and nothing added on top: if
     * `FollowupSynthesisParser` would have handed this to a teacher, the probe
     * passes; if it would have dropped it, the probe fails and the operator is
     * told the model answered but not in a usable shape.
     */
    public static function isUsable(string $answer): bool
    {
        return FollowupSynthesisParser::parse($answer) !== null;
    }

    /**
     * How many of the six sections came back readable — for the operator's
     * sentence, never for the pass/fail decision, which is `isUsable()`.
     *
     * A COUNT, NEVER THE TEXT. The answer to this probe is invented prose about
     * an invented student and would be harmless to display, but the screen it
     * would be displayed on is a settings page, the habit it would establish is
     * «AI answers get echoed into the backoffice», and the next thing echoed
     * would not be synthetic. The operator is told how many sections arrived.
     */
    public static function sectionsFound(string $answer): int
    {
        $synthesis = FollowupSynthesisParser::parse($answer);

        if ($synthesis === null) {
            return 0;
        }

        return count(array_filter([
            $synthesis->summary !== '',
            $synthesis->positiveSignals !== [],
            $synthesis->attentionSignals !== [],
            $synthesis->whatChanged !== [],
            $synthesis->nextSteps !== [],
            $synthesis->cautions !== [],
        ]));
    }
}
