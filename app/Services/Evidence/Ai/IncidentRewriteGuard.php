<?php

namespace App\Services\Evidence\Ai;

use Illuminate\Support\Str;

/**
 * The answer to «what if it agrees to the rules and breaks them anyway», for a
 * disciplinary occurrence description — the Registos equivalent of
 * `App\Services\Reporting\Writing\RewriteGuard` (SUP-U8FMAE).
 *
 * ITS OWN CLASS, NOT A REUSE OF THE REPORTING GUARD. `RewriteGuard` is internal
 * to `App\Services\Reporting\Writing`, so this module builds its own version in
 * the same mould rather than importing across the boundary. The lexicon of
 * invented claims is the same shape of risk — a model asked to reword a
 * disciplinary note is exactly as tempted to explain a cause, propose a
 * measure or characterise the student as one asked to reword a report section
 * — so the checks below are deliberately close to the original.
 *
 * NOT A SEMANTIC PARSER. Every check is a bounded, mechanical comparison
 * between the text that was sent and the text that came back, and every one of
 * them can only ever produce a false REJECTION — a suggestion wrongly refused
 * costs a teacher one click; a sentence wrongly accepted goes into a record
 * about a child.
 */
class IncidentRewriteGuard
{
    /**
     * @var list<string>
     */
    protected const INVENTED_CLAIMS = [
        // Causes and characterisations nobody validated.
        'falta de estudo', 'habitos de estudo', 'desmotiva', 'desinteress', 'autoestima',
        'insegur', 'excesso de confianca', 'deficit de atencao', 'defice de atencao',
        'falta de atencao', 'falta de concentracao', 'imaturidade', 'dislexia',
        'hiperatividade', 'ansiedade', 'absentismo', 'contexto familiar', 'apoio familiar',
        'disciplinad', 'exemplar', 'irrepreensivel', 'respeitador', 'comportad', 'perturbador',
        'excelente', 'otimo', 'excecional', 'notavel', 'brilhante', 'louvavel', 'meritorio',
        'lamentavel', 'preocupante', 'insuficiente', 'deficiente', 'inaceitavel',

        // Strategies and measures nobody chose.
        'tutoria', 'apoio individual', 'apoio educativo', 'plano de recuperacao',
        'plano de acompanhamento', 'encaminhamento', 'reforco educativo', 'medidas seletivas',
        'medidas adicionais', 'medidas universais', 'recomenda-se', 'sugere-se', 'devera',
        'devera-se', 'e aconselhavel', 'importa que',

        // Legislation and process, invented or otherwise.
        'decreto lei', 'portaria', 'despacho', 'legislacao', 'diploma legal',
        'nos termos do', 'ao abrigo do', 'processo disciplinar', 'participacao disciplinar',

        // Diagnoses.
        'diagnostic', 'patologia', 'necessidades educativas', 'perturbacao',

        // Words that turn an observation into a decision or a certainty.
        'definitiv', 'homologad', 'oficialmente', 'confirmad', 'comprova', 'demonstra',
        'prova que', 'evidencia que',
    ];

    /**
     * @var list<list<string>>
     */
    protected const LOAD_BEARING = [
        // A reservation, once written, may not be dropped.
        ['reserva', 'aparentemente', 'segundo relatado', 'segundo o relato'],
        // Absence of certainty is not a finding.
        ['nao foi possivel apurar', 'nao se confirma', 'nao e claro'],
    ];

    /** Countable phrases whose number may not grow. See IncidentProtectedFacts on «um». */
    protected const SINGULAR_UNITS = '/(?<![\p{L}\p{N}])(?:um|uma)\s+(?:alun[oa]s?|colegas?|turmas?'
        .'|registos?|incidentes?|ocorr[êe]ncias?)(?![\p{L}\p{N}])/iu';

    /** Tidy what can be tidied, before deciding whether to refuse it. */
    public function normalise(string $answer, string $sent): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $answer);

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

    public function inspect(IncidentProtectedFacts $facts, string $answer): IncidentRewriteVerdict
    {
        if (trim($answer) === '') {
            return IncidentRewriteVerdict::rejected('empty', 'the answer was blank');
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

    protected function checkFormat(string $answer): ?IncidentRewriteVerdict
    {
        if (preg_match('/<\s*\/?\s*[a-z!]/i', $answer) === 1) {
            return IncidentRewriteVerdict::rejected('html', 'the answer contained markup');
        }

        if (str_contains($answer, '```') || str_contains($answer, '~~~')) {
            return IncidentRewriteVerdict::rejected('code_fence', 'the answer contained a fence');
        }

        return null;
    }

    protected function checkMarkers(IncidentProtectedFacts $facts, string $answer): ?IncidentRewriteVerdict
    {
        $unknown = $facts->unknownMarkersIn($answer);

        if ($unknown !== []) {
            return IncidentRewriteVerdict::rejected('unknown_marker', 'invented '.implode(', ', $unknown));
        }

        if (preg_match('/\d/u', $answer) === 1) {
            return IncidentRewriteVerdict::rejected('loose_digit', 'the answer wrote a number of its own');
        }

        $expected = $facts->expectedCounts();
        $actual = $facts->countIn($answer);

        foreach ($expected as $marker => $count) {
            if (($actual[$marker] ?? 0) !== $count) {
                return IncidentRewriteVerdict::rejected(
                    'marker_count',
                    $marker.' expected '.$count.', got '.($actual[$marker] ?? 0),
                );
            }
        }

        return null;
    }

    protected function checkSize(string $sent, string $answer): ?IncidentRewriteVerdict
    {
        $before = mb_strlen($sent);
        $after = mb_strlen($answer);

        if ($after > ($before * 1.5) + 80) {
            return IncidentRewriteVerdict::rejected('grew', $before.' to '.$after.' characters');
        }

        if ($after < $before * 0.4) {
            return IncidentRewriteVerdict::rejected('shrank', $before.' to '.$after.' characters');
        }

        return null;
    }

    protected function checkMeaning(string $sent, string $answer): IncidentRewriteVerdict
    {
        $before = self::fold($sent);
        $after = self::fold($answer);

        foreach (self::INVENTED_CLAIMS as $claim) {
            $needle = self::fold($claim);

            if (str_contains($after, $needle) && ! str_contains($before, $needle)) {
                return IncidentRewriteVerdict::rejected('invented_claim', 'added «'.$claim.'»');
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
                return IncidentRewriteVerdict::rejected('dropped_hedge', 'lost «'.$group[0].'»');
            }
        }

        preg_match_all(self::SINGULAR_UNITS, $sent, $sentMatches);
        preg_match_all(self::SINGULAR_UNITS, $answer, $answerMatches);

        if (count($answerMatches[0]) > count($sentMatches[0])) {
            return IncidentRewriteVerdict::rejected('invented_quantity', 'more «um <unidade>» than were sent');
        }

        return IncidentRewriteVerdict::accepted();
    }

    protected static function fold(string $text): string
    {
        $folded = Str::lower(Str::ascii($text));

        return (string) preg_replace('/\s+/u', ' ', str_replace(['-', '_'], ' ', $folded));
    }
}
