<?php

namespace App\Services\Reporting\Writing;

use Illuminate\Support\Str;

/**
 * The answer to «what if it agrees to the rules and breaks them anyway» (§12, §45).
 *
 * NOT A SEMANTIC PARSER, AND NOT TRYING TO BE. §45 is explicit that proving a
 * rewrite added no meaning is not a problem this project is going to solve, and
 * that the right response to that is to be conservative rather than clever. So
 * every check below is a bounded, mechanical comparison between the text that
 * was sent and the text that came back, and every one of them can only ever
 * produce a false REJECTION. A suggestion wrongly refused costs a teacher one
 * click. A sentence wrongly accepted goes into a document about a child.
 *
 * THE CHECKS, AND WHAT EACH ONE IS FOR:
 *
 *   markers      every figure left as [[FA]] and has to come back as [[FA]], the
 *                same number of times, with no marker that never existed. This is
 *                what makes «60,3% became 61,3%» impossible rather than unlikely.
 *
 *   digits       the answer may not contain a digit at all. Every real number is
 *                inside a marker, so a loose digit is a number the model wrote
 *                itself.
 *
 *   quantities   «um aluno» phrases may not multiply. Cardinal words from two
 *                upwards are markers already; this closes the one gap
 *                ProtectedFacts deliberately leaves open.
 *
 *   claims       a bounded vocabulary of things a report may not start saying:
 *                difficulties, causes, diagnoses, strategies, measures,
 *                legislation, characterisations of behaviour, and words that
 *                turn a proposal into a decision. New in the answer and absent
 *                from what was sent means refused.
 *
 *   hedges       the mirror image. A reservation, an absence of data, a
 *                self-assessment or a proposal that was in the text has to still
 *                be there — losing «autoavaliou-se» turns what a child said
 *                about themselves into a finding of the teacher's (§3, §35).
 *
 *   size         an answer half again as long as the question has added
 *                something, whatever it is.
 *
 *   format       plain text only. No HTML, no Markdown, no code fences (§31).
 *
 * The lexicons are deliberately in Portuguese and deliberately finite. A list
 * that tried to be exhaustive would be a list nobody could review; these are the
 * sentences that actually turn up when a language model is asked to write
 * pedagogically about a class it knows nothing about.
 */
class RewriteGuard
{
    /**
     * Things the answer may not begin to say.
     *
     * Matched against an accent-folded, lowercased copy of both texts, so
     * «Desmotivação» and «desmotivacao» are one entry. Substring matching is
     * intentional: «insegur» catches insegurança and inseguro without listing
     * both, and every entry is long enough that an accidental hit is unlikely.
     *
     * @var list<string>
     */
    protected const INVENTED_CLAIMS = [
        // Difficulties and causes nobody validated (§37, §44).
        'falta de estudo', 'habitos de estudo', 'desmotiva', 'desinteress', 'autoestima',
        'insegur', 'excesso de confianca', 'deficit de atencao', 'defice de atencao',
        'falta de atencao', 'falta de concentracao', 'falta de empenho', 'falta de trabalho',
        'falta de assiduidade', 'imaturidade', 'dislexia', 'hiperatividade', 'ansiedade',
        'absentismo', 'indisciplina', 'contexto familiar', 'apoio familiar',

        // Characterisations of behaviour nobody made (§36).
        'disciplinad', 'exemplar', 'irrepreensivel', 'respeitador', 'comportad', 'perturbador',
        'empenhad', 'motivad', 'responsavel',

        // The evaluative adjectives, separately.
        //
        // A TEST FOUND THIS GAP. «A turma apresenta comportamento excelente»
        // walked past a list that refused «disciplinada» and «comportada»,
        // because the noun «comportamento» is neutral — it is the heading of a
        // whole section — and the judgement was carried entirely by the
        // adjective beside it. Characterising a class is a thing a teacher does
        // in a form, and no adjective may arrive without one.
        'excelente', 'otimo', 'excecional', 'notavel', 'brilhante', 'louvavel', 'meritorio',
        'lamentavel', 'preocupante', 'insuficiente', 'deficiente', 'inaceitavel',

        // Strategies and measures nobody chose (§38).
        'tutoria', 'apoio individual', 'apoio educativo', 'avaliacao diferenciada',
        'pedagogia diferenciada', 'plano de recuperacao', 'plano de acompanhamento',
        'encaminhamento', 'reforco educativo', 'coadjuvacao', 'medidas seletivas',
        'medidas adicionais', 'medidas universais', 'aula de apoio', 'explicacao',
        'recomenda-se', 'sugere-se', 'devera', 'devera-se', 'e aconselhavel', 'importa que',

        // Legislation, invented or otherwise (§39).
        'decreto lei', 'portaria', 'despacho', 'legislacao', 'diploma legal',
        'nos termos do', 'ao abrigo do',

        // Diagnoses (§3).
        'diagnostic', 'patologia', 'necessidades educativas', 'perturbacao',

        // Words that turn a proposal into a decision, or a reading into a fact (§3).
        'definitiv', 'homologad', 'oficialmente', 'confirmad', 'comprova', 'demonstra',
        'prova que', 'evidencia que',
    ];

    /**
     * Meanings that have to survive if they were there.
     *
     * Each group is one concept expressed several ways, so a rewrite is free to
     * say it differently and only refused for dropping it entirely.
     *
     * @var list<list<string>>
     */
    protected const LOAD_BEARING = [
        // A child's own reading of themselves is never the teacher's finding.
        ['autoavalia', 'autoavaliou', 'autoavaliaram', 'autoavaliacao'],
        // A proposal is not a decision.
        ['proposta', 'propost', 'propoe', 'propor'],
        // Absence of data is not a bad result.
        ['nao existe', 'nao foram apurados', 'ausencia', 'inexist', 'nao ha', 'sem resultados'],
        // A figure that rests on part of the instruments says so.
        ['reserva', 'deve ser lido', 'parte dos instrumentos'],
        // The sentence that stops a reader reading zero into an empty cell.
        ['nao corresponde', 'nao significa', 'nao equivale'],
    ];

    /** Countable phrases whose number may not grow. See ProtectedFacts on «um». */
    protected const SINGULAR_UNITS = '/(?<![\p{L}\p{N}])(?:um|uma)\s+(?:alun[oa]s?|turmas?|registos?'
        .'|instrumentos?|dom[íi]nios?|n[íi]ve(?:l|is)|per[íi]odos?'
        .'|classifica[çc][ãa]o|classifica[çc][õo]es)(?![\p{L}\p{N}])/iu';

    /**
     * Tidy what can be tidied, before deciding whether to refuse it (§31).
     *
     * NORMALISATION, NOT REPAIR. Nothing here changes a word: it removes
     * decoration a plain-text answer should not have carried in the first place
     * and puts the whitespace back the way the rest of the module writes it.
     * Anything that would need judgement to fix is refused instead.
     *
     * The em dash is the one stylistic rule enforced here rather than merely
     * asked for. The whole narrative layer had its em dashes removed on purpose
     * — they are the clearest tell that a machine wrote a sentence — and letting
     * this feature put them back would undo that one paragraph at a time. They
     * are only rewritten when the original had none, so a teacher who uses them
     * keeps them.
     */
    public function normalise(string $answer, string $sent): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $answer);

        // Markdown decoration a plain-text answer should not have. The leading
        // allowance is spaces and tabs, never `\s`: `\s` matches a newline, so
        // the pattern would eat the blank line between two paragraphs on its way
        // to the bullet that starts the second one.
        $text = (string) preg_replace('/^[ \t]{0,3}#{1,6}[ \t]+/mu', '', $text);
        $text = (string) preg_replace('/^[ \t]{0,3}[-*+][ \t]+/mu', '', $text);
        $text = (string) preg_replace('/(\*\*|__)(.+?)\1/su', '$2', $text);

        if (! str_contains($sent, '—') && ! str_contains($sent, '–')) {
            $text = (string) preg_replace('/\s+[—–]\s+/u', ', ', $text);
        }

        $text = (string) preg_replace('/[ \t]+$/mu', '', $text);
        $text = (string) preg_replace('/\n{3,}/u', "\n\n", $text);

        return trim($text);
    }

    public function inspect(ProtectedFacts $facts, string $answer): RewriteVerdict
    {
        if (trim($answer) === '') {
            return RewriteVerdict::rejected('empty', 'the answer was blank');
        }

        if ($verdict = $this->checkFormat($answer)) {
            return $verdict;
        }

        if ($verdict = $this->checkMarkers($facts, $answer)) {
            return $verdict;
        }

        if ($verdict = $this->checkSize($facts->redacted, $answer)) {
            return $verdict;
        }

        return $this->checkMeaning($facts->redacted, $answer);
    }

    /** Plain text only (§31). Nothing here is repaired — a fenced answer is refused. */
    protected function checkFormat(string $answer): ?RewriteVerdict
    {
        if (preg_match('/<\s*\/?\s*[a-z!]/i', $answer) === 1) {
            return RewriteVerdict::rejected('html', 'the answer contained markup');
        }

        if (str_contains($answer, '```') || str_contains($answer, '~~~')) {
            return RewriteVerdict::rejected('code_fence', 'the answer contained a fence');
        }

        return null;
    }

    /** Every figure came back, exactly as many times, and none was invented (§13, §14). */
    protected function checkMarkers(ProtectedFacts $facts, string $answer): ?RewriteVerdict
    {
        $unknown = $facts->unknownMarkersIn($answer);

        if ($unknown !== []) {
            return RewriteVerdict::rejected('unknown_marker', 'invented '.implode(', ', $unknown));
        }

        // Checked before the counts, because it is the more diagnostic finding:
        // an answer that dropped a marker AND wrote a digit did not lose a
        // number, it replaced one. The markers are lettered, so the text that
        // went out had no digit in it anywhere and any digit that comes back is
        // one the model wrote itself.
        if (preg_match('/\d/u', $answer) === 1) {
            return RewriteVerdict::rejected('loose_digit', 'the answer wrote a number of its own');
        }

        $expected = $facts->expectedCounts();
        $actual = $facts->countIn($answer);

        foreach ($expected as $marker => $count) {
            if (($actual[$marker] ?? 0) !== $count) {
                return RewriteVerdict::rejected(
                    'marker_count',
                    $marker.' expected '.$count.', got '.($actual[$marker] ?? 0),
                );
            }
        }

        return null;
    }

    protected function checkSize(string $sent, string $answer): ?RewriteVerdict
    {
        $before = mb_strlen($sent);
        $after = mb_strlen($answer);

        // Half again as long has added something, whatever the mode asked for.
        // The flat allowance keeps very short sections — «Sem registos no
        // período.» — from failing on a rounding of nothing.
        if ($after > ($before * 1.5) + 80) {
            return RewriteVerdict::rejected('grew', $before.' to '.$after.' characters');
        }

        if ($after < $before * 0.4) {
            return RewriteVerdict::rejected('shrank', $before.' to '.$after.' characters');
        }

        return null;
    }

    protected function checkMeaning(string $sent, string $answer): RewriteVerdict
    {
        $before = self::fold($sent);
        $after = self::fold($answer);

        foreach (self::INVENTED_CLAIMS as $claim) {
            // The lexicon is folded too: it is written the way a person would
            // read it, and «decreto-lei» has to survive the hyphen flattening.
            $needle = self::fold($claim);

            if (str_contains($after, $needle) && ! str_contains($before, $needle)) {
                return RewriteVerdict::rejected('invented_claim', 'added «'.$claim.'»');
            }
        }

        foreach (self::LOAD_BEARING as $group) {
            $wasThere = false;
            $stillThere = false;

            foreach ($group as $term) {
                $needle = self::fold($term);

                $wasThere = $wasThere || str_contains($before, $needle);
                $stillThere = $stillThere || str_contains($after, $needle);
            }

            if ($wasThere && ! $stillThere) {
                return RewriteVerdict::rejected('dropped_hedge', 'lost «'.$group[0].'»');
            }
        }

        foreach (WritingGlossary::loadBearingTerms() as $term) {
            if (str_contains($before, self::fold($term)) && ! str_contains($after, self::fold($term))) {
                return RewriteVerdict::rejected('dropped_term', 'lost «'.$term.'»');
            }
        }

        preg_match_all(self::SINGULAR_UNITS, $sent, $sentMatches);
        preg_match_all(self::SINGULAR_UNITS, $answer, $answerMatches);

        if (count($answerMatches[0]) > count($sentMatches[0])) {
            return RewriteVerdict::rejected('invented_quantity', 'more «um <unidade>» than were sent');
        }

        return RewriteVerdict::accepted();
    }

    /**
     * Normalise a text so the lexicons can be short.
     *
     * Lowercased, accent-folded, hyphens and runs of whitespace flattened to
     * single spaces — «Défice de Atenção» and «defice de  atencao» are the same
     * string by the time they are compared.
     */
    protected static function fold(string $text): string
    {
        $folded = Str::lower(Str::ascii($text));

        return (string) preg_replace('/\s+/u', ' ', str_replace(['-', '_'], ' ', $folded));
    }
}
