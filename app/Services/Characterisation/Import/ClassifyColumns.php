<?php

namespace App\Services\Characterisation\Import;

use App\Models\CatalogueFamily;
use App\Models\SupportMeasureLevel;
use App\Support\Characterisation\AcronymDictionary;
use Illuminate\Support\Str;

/**
 * Decides what each column of an imported table is, by reading its header.
 *
 * By header text only — never by position. School exports put their columns in
 * whatever order the person who built the sheet preferred, and a parser that
 * counts columns is a parser that silently writes the wrong thing the first
 * time someone inserts one. The roster importer learned this already: it finds
 * its header row by content too.
 */
class ClassifyColumns
{
    public function __construct(
        private readonly AcronymDictionary $acronyms = new AcronymDictionary,
    ) {}

    /**
     * Header fragments, per role. First match wins, so the list is ordered from
     * most specific to least: "n.º de processo" must be tested before "n.º".
     *
     * MEASURES IS TESTED BEFORE NEEDS, AND THE ORDER IS LOAD-BEARING. The
     * column schools actually write is «Medidas de apoio à aprendizagem e à
     * inclusão» — the statute's own words — and it contains "apoio". Tested
     * after Needs, that column classified as free text: it never reached the
     * resolver, so «MU a) b); RTP» was copied verbatim into a child's
     * «Necessidades» instead of being typed into (B) or held back in (D). The
     * one column this feature exists to read was the one it read wrongly.
     *
     * A column that says "apoio" WITHOUT saying "medida" — «Apoios»,
     * «Recursos específicos de apoio» — is still Needs, which is destination
     * (C) and correct.
     *
     * @var array<string, list<string>>
     */
    private const PATTERNS = [
        ColumnRole::SchoolNumber->value => ['n.o de processo', 'no de processo', 'numero de processo', 'n processo', 'processo', 'n.o proc', 'no proc', 'n.o matr', 'matricula'],
        ColumnRole::StudentName->value => ['nome do aluno', 'nome completo', 'aluno', 'aluna', 'nome', 'discente'],
        ColumnRole::ClassNumber->value => ['n.o da turma', 'numero da turma', 'n.o', 'no.', 'numero', 'num'],
        ColumnRole::Measures->value => ['medida', 'suporte a aprendizagem', 'decreto', 'dl 54', 'dl54', '54/2018'],
        ColumnRole::Strengths->value => ['potencialidade', 'pontos fortes', 'forcas'],
        ColumnRole::Interests->value => ['interesse', 'gostos'],
        ColumnRole::Barriers->value => ['barreira', 'obstaculo', 'constrangimento'],
        // Before Needs, and separate from it: «apoios mobilizados» is not
        // «necessidades do aluno». Tested after Measures, so «medidas de apoio»
        // still reaches the resolver as measures.
        ColumnRole::Resources->value => ['apoio', 'recurso', 'tecnico especializado', 'equipa'],
        ColumnRole::Needs->value => ['necessidade', 'dificuldade'],
        ColumnRole::Participation->value => ['participacao', 'envolvimento', 'comportamento'],
        ColumnRole::Characterisation->value => ['caracterizacao', 'observacoes', 'observacao', 'notas', 'nota', 'descricao', 'perfil'],
    ];

    /**
     * @return list<ClassifiedColumn>
     */
    public function classify(TableGrid $grid): array
    {
        $columns = [];

        foreach ($grid->headers as $index => $header) {
            $role = $this->roleFor($header);

            $columns[] = new ClassifiedColumn(
                index: $index,
                header: trim($header),
                role: $role,
                level: $role === ColumnRole::Measures ? $this->levelFor($header) : null,
            );
        }

        return $this->withoutDuplicateIdentifiers($columns);
    }

    private function roleFor(string $header): ColumnRole
    {
        $folded = $this->fold($header);

        if ($folded === '') {
            return ColumnRole::Unknown;
        }

        foreach (self::PATTERNS as $role => $fragments) {
            foreach ($fragments as $fragment) {
                if (str_contains($folded, $fragment)) {
                    return ColumnRole::from($role);
                }
            }
        }

        return $this->roleFromAcronym($header) ?? ColumnRole::Unknown;
    }

    /**
     * A header that doesn't say "medida" or "apoio" may still say nothing but a
     * confirmed acronym — the real tables this feature was built to read use
     * "MU"/"MS"/"MA" as their measures columns' entire headers, never the
     * spelled-out words. AcronymDictionary is the single source of truth for
     * which acronyms are confirmed (§20): this must not hardcode a second list
     * of legal codes, so it asks the dictionary rather than matching "MU"
     * itself.
     *
     * The match is on the WHOLE header, not a fragment of it — a header must BE
     * the acronym, not merely contain it, or "Outras medidas/recursos" would
     * classify by a stray substring and a student's own name could too.
     *
     * An unconfirmed acronym (RTP, PEI, ATE...) is recognised as an acronym but
     * carries no level and no family, so it resolves to nothing here and stays
     * Unknown — exactly the dictionary's contract, and exactly why RTP/PEI must
     * not become a measures column even though they are legally adjacent.
     */
    private function roleFromAcronym(string $header): ?ColumnRole
    {
        $entry = $this->acronyms->find(trim($header));

        if ($entry === null || ! $entry->isConfirmed()) {
            return null;
        }

        if ($entry->level !== null) {
            return ColumnRole::Measures;
        }

        if ($entry->family === CatalogueFamily::SupportResource) {
            return ColumnRole::Resources;
        }

        return null;
    }

    /**
     * A measures column may name its own level — "Medidas seletivas" — and that
     * is what lets the letters underneath it mean something. Without it the
     * column carries no level and the cells stay ambiguous, which is correct.
     *
     * The dictionary is consulted first: a header that is bare "MU" carries no
     * word like "universa" for the text match below to find, but the confirmed
     * acronym already names its level.
     */
    private function levelFor(string $header): ?SupportMeasureLevel
    {
        $entry = $this->acronyms->find(trim($header));

        if ($entry !== null && $entry->level !== null) {
            return $entry->level;
        }

        $folded = $this->fold($header);

        return match (true) {
            str_contains($folded, 'universa') => SupportMeasureLevel::Universal,
            str_contains($folded, 'seletiv') || str_contains($folded, 'selectiv') => SupportMeasureLevel::Selective,
            str_contains($folded, 'adicion') => SupportMeasureLevel::Additional,
            default => null,
        };
    }

    /**
     * Two columns claiming to be the name is not a table this parser can read
     * confidently, so the later one is demoted to Unknown rather than silently
     * overriding the first. The teacher sees it unclassified and says which is
     * which.
     *
     * @param  list<ClassifiedColumn>  $columns
     * @return list<ClassifiedColumn>
     */
    private function withoutDuplicateIdentifiers(array $columns): array
    {
        $seen = [];

        return array_map(function (ClassifiedColumn $column) use (&$seen) {
            if (! $column->role->isIdentifying()) {
                return $column;
            }

            if (isset($seen[$column->role->value])) {
                return new ClassifiedColumn($column->index, $column->header, ColumnRole::Unknown);
            }

            $seen[$column->role->value] = true;

            return $column;
        }, $columns);
    }

    /**
     * Str::ascii, not iconv//TRANSLIT: the latter is locale- and
     * platform-dependent and turns «Observações» into «Observac~oes» on
     * Windows, so a header would classify differently on a developer's machine
     * than in CI. Accent folding has to be the same everywhere or it is not
     * folding at all.
     */
    private function fold(string $value): string
    {
        $folded = mb_strtolower(Str::ascii($value));

        return trim(preg_replace('/\s+/u', ' ', $folded) ?? '');
    }
}
