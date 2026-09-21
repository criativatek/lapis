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
     * The SAME fragments the Characterisation role matches on above — reused
     * here to detect when a column classified as Measures ALSO names free
     * text in its own header. It is the SAME array element, not a copy of its
     * contents: a second literal list would drift from the first the moment
     * anybody taught Characterisation a new word. See ClassifiedColumn::$alsoFreeText for why this
     * matters and what it changes.
     */
    private const FREE_TEXT_FRAGMENTS = self::PATTERNS[ColumnRole::Characterisation->value];

    /**
     * A strong majority, not a bare one: this is a LAST-RESORT guess, run
     * only once header text has already failed to name any identifying
     * column at all (see inferNameColumnFromContent()'s own comment), so it
     * must be very sure of itself before it is allowed to run at all.
     */
    private const NAME_INFERENCE_MIN_RATIO = 0.8;

    /**
     * A name is a couple of words, not a sentence. Capped well above any
     * real Portuguese full name («Maria da Conceição Vieira dos Santos» is
     * under 40) but well below an observation a teacher would actually
     * write, so a free-text column's occasional short, name-shaped-looking
     * sentence fragment cannot accumulate a majority on its own.
     */
    private const NAME_INFERENCE_MAX_CELL_LENGTH = 40;

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
                alsoFreeText: $role === ColumnRole::Measures && $this->namesFreeTextToo($header),
            );
        }

        $columns = $this->withoutDuplicateIdentifiers($columns);

        return $this->inferNameColumnFromContent($columns, $grid);
    }

    /**
     * LAST RESORT: when NO header in the table named an identifying column
     * at all, infer which column holds the student's name from what is
     * actually written in it, rather than leave every row unmatchable.
     *
     * This is the production "0 de 0" one stage further back than it looks.
     * A real export's student-name column can carry NO printed title in
     * either header level — a blank cell sits above a column of names on a
     * printed form — so header-text classification (roleFor(), above) never
     * has anything to match and the column stays Unknown forever. Every row
     * then fails BuildCharacterisationPreview's "identifies nobody" check
     * for the SAME structural reason, and the teacher sees "todas as linhas
     * foram identificadas como rodapé ou totais" — which reads like every
     * one of HER rows was rejected, when in fact the importer never had a
     * name column to read from at all.
     *
     * THE "NO POSITION-BASED GUESSING" RULE (this class's own docblock)
     * STAYS INTACT for any table that labels its name column — a school is
     * still free to put "Nome" wherever it likes, and this method never
     * runs when header text already found StudentName or ClassNumber
     * somewhere. This only fires in the one situation position-guessing
     * would otherwise be the sole option: no label anywhere to trust.
     *
     * Guessing by CONTENT instead of position: the candidate column is
     * whichever one's non-empty data cells overwhelmingly read like a
     * person's name (LooksLikePersonName — the exact same shape
     * NormaliseExtractedTable already trusts to rule a cell OUT of being a
     * caption, promoted so this is not a second, independently-drifting
     * heuristic). A column already classified confidently as something else
     * — Measures, Resources, Characterisation free text, any recognised
     * role — is never a candidate, so a school's real "Apoios" column can
     * never be mistaken for the name column just because a few resource
     * names happen to look capitalised. And a free-text observations column
     * is excluded twice over: it is virtually never Unknown once headed
     * "Observações" (it would already be Characterisation), and even an
     * unlabelled one is guarded by NAME_INFERENCE_MAX_CELL_LENGTH, so a
     * paragraph of prose cannot accumulate the strong majority this
     * requires just because it occasionally opens with two capitalised
     * words.
     *
     * @param  list<ClassifiedColumn>  $columns
     * @return list<ClassifiedColumn>
     */
    private function inferNameColumnFromContent(array $columns, TableGrid $grid): array
    {
        // FindHeaderRow calls classify() with an EMPTY rows array purely to
        // score a candidate header row by its OWN text — there is no body
        // to read cell content from yet, and there must never be: scoring a
        // header candidate against the very rows it might not even be the
        // header of would be nonsensical. No rows, no inference — that path
        // is unaffected by this method, exactly as it was before it existed.
        if ($grid->isEmpty()) {
            return $columns;
        }

        foreach ($columns as $column) {
            if ($column->role->isIdentifying()) {
                // A header already named the student — by text, not
                // position — so guessing from content here would be a
                // second, unwanted opinion on a question the header already
                // answered. A table that labels its name column must
                // behave exactly as it did before this method existed.
                return $columns;
            }
        }

        $bestIndex = null;

        foreach ($columns as $column) {
            if ($column->role !== ColumnRole::Unknown) {
                // Confidently something else already — never a candidate,
                // however name-shaped a few of its cells might coincidentally
                // look (see this method's own docblock).
                continue;
            }

            [$nameLike, $total] = $this->nameLikeCellStats($grid, $column->index);

            if ($total === 0) {
                continue;
            }

            if (($nameLike / $total) < self::NAME_INFERENCE_MIN_RATIO) {
                continue;
            }

            // Columns are visited left to right ($grid->headers order), so
            // the FIRST one to clear the majority bar is already the
            // leftmost qualifying column — nothing further to compare it
            // against.
            $bestIndex = $column->index;

            break;
        }

        if ($bestIndex === null) {
            // Nothing in the table reads like a name column even by
            // content — a genuinely unreadable table. Left as Unknown
            // rather than guessed at, exactly like every other column this
            // parser cannot recognise: the honest "não foi possível"
            // failure, not a wrong guess dressed up as a right one.
            return $columns;
        }

        return array_map(
            fn (ClassifiedColumn $column) => $column->index === $bestIndex
                ? new ClassifiedColumn(
                    index: $column->index,
                    header: $column->header,
                    role: ColumnRole::StudentName,
                    inferredFromContent: true,
                )
                : $column,
            $columns,
        );
    }

    /**
     * @return array{0: int, 1: int} [how many of this column's non-empty
     *                               cells look like a person's name, how many
     *                               non-empty cells it has at all]
     */
    private function nameLikeCellStats(TableGrid $grid, int $columnIndex): array
    {
        $nameLike = 0;
        $total = 0;

        foreach ($grid->rows as $row) {
            $cell = $grid->cell($row, $columnIndex);

            if ($cell === '') {
                continue;
            }

            $total++;

            if (mb_strlen($cell) > self::NAME_INFERENCE_MAX_CELL_LENGTH) {
                // Too long to be a name — see NAME_INFERENCE_MAX_CELL_LENGTH's
                // own comment on why this is what keeps a free-text column
                // from winning by accident.
                continue;
            }

            if (LooksLikePersonName::check($cell)) {
                $nameLike++;
            }
        }

        return [$nameLike, $total];
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
     *
     * A MULTI-LEVEL HEADER JOINS ITS FRAGMENTS WITH A SPACE, AND THAT BREAKS
     * THE WHOLE-HEADER DICTIONARY LOOKUP. A table whose "Medidas" top row
     * spans MU/MS/MA (a real, common shape — see the fixture) produces a
     * joined column header of "Medidas MU", not bare "MU" —
     * NormaliseExtractedTable::joinHeaderLevels() concatenates every level
     * that has text for that column, on purpose, so nothing is silently
     * dropped. `$this->acronyms->find(trim($header))` never matches "Medidas
     * MU" as a whole, so a bare dictionary lookup on the full header would
     * leave the level null for exactly the tables this feature exists to
     * read — the acronym is there, it is just not alone. Each individual word
     * of the header is tried too, so "Medidas" (no level) and "MU" (a
     * confirmed level) are checked separately, in header order, and the
     * first word that names a level wins.
     */
    private function levelFor(string $header): ?SupportMeasureLevel
    {
        $trimmed = trim($header);

        $entry = $this->acronyms->find($trimmed);

        if ($entry !== null && $entry->level !== null) {
            return $entry->level;
        }

        foreach (preg_split('/\s+/u', $trimmed) ?: [] as $word) {
            if ($word === '') {
                continue;
            }

            $wordEntry = $this->acronyms->find($word);

            if ($wordEntry !== null && $wordEntry->level !== null) {
                return $wordEntry->level;
            }
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
     * Whether a header already classified as Measures ALSO names free text —
     * «Outras medidas/recursos / Observações» says "medidas" (why it is
     * Measures at all — see PATTERNS' own comment on load-bearing order) AND
     * "observações" in the same breath. Reuses FREE_TEXT_FRAGMENTS, the exact
     * fragments that would have made it classify as Characterisation had
     * Measures not been tested first, so the two lists can never drift apart.
     */
    private function namesFreeTextToo(string $header): bool
    {
        $folded = $this->fold($header);

        foreach (self::FREE_TEXT_FRAGMENTS as $fragment) {
            if (str_contains($folded, $fragment)) {
                return true;
            }
        }

        return false;
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
