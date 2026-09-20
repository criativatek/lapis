<?php

namespace App\Support\Characterisation;

use App\Models\CatalogueFamily;
use App\Models\SupportMeasureCode;
use App\Models\SupportMeasureLevel;
use App\Support\Interventions\InterventionLegalFramework;
use App\Support\Interventions\LegalFrameworkResolver;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Reads a cell of a school's paperwork against the legal framework that
 * actually applies — and holds no legal knowledge of its own.
 *
 * EVERY JURIDICAL FACT COMES FROM THE FRAMEWORK. Which measures exist, what
 * level each sits at, which article and alínea names it, whether it is still
 * selectable: all of it is asked, per call, of the
 * `InterventionLegalFramework` resolved for the applicable date. There is no
 * list of measures here, no level table, no article map. When the law changes,
 * a new framework version answers differently and this class needs no edit —
 * which is the whole reason the catalogue and the parser were kept apart.
 *
 * The rules it enforces, in the order they matter:
 *
 * 1. A sub-paragraph letter resolves ONLY with a level. «b)» is genuinely
 *    ambiguous — the current regime names a b) under each of the three levels
 *    (articles 8.º, 9.º and 10.º), so a bare letter identifies three different
 *    measures at once. With a level in hand it identifies exactly one, and the
 *    framework is what says which.
 * 2. A named measure («ACNS», or the diploma's own designation) resolves on its
 *    own, and a level beside it is corroboration, not a requirement.
 * 3. A level with no measure and no letter is ambiguous: knowing the level says
 *    nothing about which measure was meant.
 * 4. A level that contradicts the measure's own level is ambiguous, whatever
 *    the measure was. Two sources disagreeing is not evidence, it is a reason
 *    to ask.
 * 5. A measure this framework does not name — introduced later, or revoked —
 *    is NOT recognised. `levelFor()` returning null is an answer, not a gap.
 */
class DecreeLaw54CodeResolver implements LegalCodeResolver
{
    public function __construct(
        private readonly AcronymDictionary $dictionary,
        private readonly LegalFrameworkResolver $frameworks,
        private readonly CurrentOrganization $organization,
    ) {}

    public function resolveCell(string $cell, ?SupportMeasureLevel $columnLevel = null, ?CarbonInterface $on = null): array
    {
        $framework = $this->framework($on);

        if ($framework === null || ! $framework->hasLegalTaxonomy()) {
            // The organization's jurisdiction has no legal taxonomy — or is one
            // Lapispro has never encoded. «MU» is then just two letters, and
            // reading it as a Portuguese measure level would apply one
            // country's law to another country's school. Everything in the cell
            // reaches the preview unrecognised, for a person to deal with.
            return array_map(
                fn (string $statement) => CodeResolution::unrecognised(
                    rawToken: $statement,
                    note: (string) __('Não há enquadramento legal aplicável a esta organização, por isso os códigos não são interpretados.'),
                ),
                $this->splitStatements($cell),
            );
        }

        $resolutions = [];

        foreach ($this->splitStatements($cell) as $statement) {
            foreach ($this->resolveStatement($statement, $columnLevel, $framework) as $resolution) {
                $resolutions[] = $resolution;
            }
        }

        return $resolutions;
    }

    public function levelFor(SupportMeasureCode $code, ?CarbonInterface $on = null): ?SupportMeasureLevel
    {
        $framework = $this->framework($on);

        if ($framework === null || ! $framework->hasLegalTaxonomy()) {
            return null;
        }

        return $framework->levelFor($code);
    }

    /**
     * The framework in force on the applicable date.
     *
     * The date is the paperwork's when the import supplies one, and otherwise
     * today's — which is the honest default for somebody typing what their
     * school's CURRENT paperwork says. It is never «the first framework in the
     * registry»: the registry resolves by date and skips any version whose
     * status is not applicable, so a draft or a future regime cannot leak in
     * through here.
     */
    private function framework(?CarbonInterface $on): ?InterventionLegalFramework
    {
        if (! $this->organization->isResolved()) {
            return null;
        }

        return $this->frameworks->for($this->organization->get(), $on ?? Carbon::now());
    }

    /**
     * Statements are separated by semicolons and line breaks only.
     *
     * Not by "+", and not by commas: "MS b) + ACNS" is one statement about one
     * measure, and splitting it would throw away precisely the context that
     * makes it readable.
     *
     * @return list<string>
     */
    private function splitStatements(string $cell): array
    {
        $parts = preg_split('/[;\r\n]+/u', $cell) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn (string $part) => $part !== ''));
    }

    /**
     * @return list<CodeResolution>
     */
    private function resolveStatement(
        string $statement,
        ?SupportMeasureLevel $columnLevel,
        InterventionLegalFramework $framework,
    ): array {
        $annotations = $this->extractAnnotations($statement);
        $codes = $this->extractCodes($statement, $framework);
        $statedLevel = $this->extractLevel($statement) ?? $columnLevel;

        if ($codes !== []) {
            return array_map(
                fn (SupportMeasureCode $code) => $this->forCode($statement, $code, $statedLevel, $annotations, $framework),
                $codes,
            );
        }

        $unknown = $this->extractUnknownAcronyms($statement, $framework);

        // A level AND a letter together name exactly one measure, and the
        // framework is what resolves the pair. This is the whole difference
        // between «MU b)» and «b)».
        if ($statedLevel !== null && $annotations !== []) {
            return array_merge(
                array_map(
                    fn (string $letter) => $this->forSubparagraph($statement, $statedLevel, $letter, $framework),
                    $annotations,
                ),
                $unknown,
            );
        }

        if ($statedLevel !== null) {
            return array_merge(
                [CodeResolution::ambiguous(
                    rawToken: $statement,
                    level: $statedLevel,
                    note: (string) __('Nível identificado, medida por identificar.'),
                )],
                $unknown,
            );
        }

        if ($annotations !== []) {
            // The canonical case, and now provably so: the regime names a b)
            // under each of the three levels, so a bare letter is three
            // measures at once.
            return array_merge(
                [CodeResolution::ambiguous(
                    rawToken: $statement,
                    unresolvedAnnotations: $annotations,
                    note: (string) __('Alínea sem nível: o diploma tem uma alínea com esta letra em cada um dos três níveis.'),
                )],
                $unknown,
            );
        }

        if ($unknown !== []) {
            return $unknown;
        }

        return [CodeResolution::unrecognised(
            rawToken: $statement,
            note: (string) __('Sem código reconhecido.'),
        )];
    }

    /**
     * The measure this framework names at a level under a given alínea.
     */
    private function forSubparagraph(
        string $statement,
        SupportMeasureLevel $level,
        string $letter,
        InterventionLegalFramework $framework,
    ): CodeResolution {
        $matches = [];

        foreach (SupportMeasureCode::cases() as $case) {
            if ($framework->levelFor($case) !== $level) {
                continue;
            }

            $reference = $framework->legalReferenceFor($case);

            if ($reference === null || ! $reference->status->isSelectable()) {
                // A revoked measure still resolves for history elsewhere, but
                // it is not something a new import may newly assign to a child.
                continue;
            }

            if ($reference->subparagraph !== null && $this->fold($reference->subparagraph) === $this->fold($letter)) {
                $matches[] = $case;
            }
        }

        if (count($matches) !== 1) {
            return CodeResolution::ambiguous(
                rawToken: $statement,
                level: $level,
                unresolvedAnnotations: [$letter],
                note: (string) __('O enquadramento em vigor não identifica uma medida única para :letter neste nível.', ['letter' => $letter]),
            );
        }

        return CodeResolution::recognised(
            rawToken: $statement,
            code: $matches[0],
            level: $level,
            note: (string) __('Resolvido pela alínea :letter do enquadramento em vigor.', ['letter' => $letter]),
        );
    }

    /**
     * @param  list<string>  $annotations
     */
    private function forCode(
        string $statement,
        SupportMeasureCode $code,
        ?SupportMeasureLevel $statedLevel,
        array $annotations,
        InterventionLegalFramework $framework,
    ): CodeResolution {
        $level = $framework->levelFor($code);

        if ($level === null) {
            // The framework applicable here does not name this measure. Storing
            // it would assert a legal fact under a regime that never said it.
            return CodeResolution::unrecognised(
                rawToken: $statement,
                scope: AcronymScope::National,
                note: (string) __('O enquadramento aplicável nesta data não nomeia esta medida.'),
            );
        }

        if ($statedLevel !== null && $statedLevel !== $level) {
            return CodeResolution::ambiguous(
                rawToken: $statement,
                unresolvedAnnotations: $annotations,
                note: (string) __('O nível indicado (:stated) não corresponde ao da medida :code (:actual).', [
                    'stated' => $statedLevel->label(),
                    'code' => $code->label(),
                    'actual' => $level->label(),
                ]),
            );
        }

        $reference = $framework->legalReferenceFor($code);

        // An alínea beside a named measure has to agree with it. «MS c) + ACNS»
        // is two sources disagreeing, and the answer to that is a question.
        if ($annotations !== [] && $reference?->subparagraph !== null) {
            $agrees = array_filter(
                $annotations,
                fn (string $letter) => $this->fold($letter) === $this->fold((string) $reference->subparagraph),
            );

            if ($agrees === []) {
                return CodeResolution::ambiguous(
                    rawToken: $statement,
                    level: $level,
                    unresolvedAnnotations: $annotations,
                    note: (string) __('A alínea indicada não é a da medida :code (:actual).', [
                        'code' => $code->label(),
                        'actual' => (string) $reference->subparagraph,
                    ]),
                );
            }
        }

        return CodeResolution::recognised(
            rawToken: $statement,
            code: $code,
            level: $level,
            note: $reference === null ? null : (string) __('Corresponde a :citation.', ['citation' => $reference->citation()]),
        );
    }

    /**
     * Sub-paragraph letters, e.g. "a)" or "b)".
     *
     * @return list<string>
     */
    private function extractAnnotations(string $statement): array
    {
        preg_match_all('/\b([a-z])\)/u', mb_strtolower($statement), $matches);

        return array_values(array_unique(array_map(fn (string $letter) => $letter.')', $matches[1])));
    }

    /**
     * Measures named outright — by the diploma's own designation, by the enum's
     * label, or by an acronym the dictionary confirms.
     *
     * The designations are read FROM THE FRAMEWORK, so a measure the catalogue
     * gains tomorrow is recognised here without this file being touched.
     *
     * @return list<SupportMeasureCode>
     */
    private function extractCodes(string $statement, InterventionLegalFramework $framework): array
    {
        $found = [];
        $haystack = $this->fold($statement);

        /** @var list<array{case: SupportMeasureCode, text: string}> $candidates */
        $candidates = [];

        foreach (SupportMeasureCode::cases() as $case) {
            $candidates[] = ['case' => $case, 'text' => $case->label()];

            $designation = $framework->legalReferenceFor($case)?->designation;

            if ($designation !== null) {
                $candidates[] = ['case' => $case, 'text' => $designation];
            }
        }

        // Longest first: «adaptações curriculares significativas» contains the
        // non-significant one's words but not the other way round, so matching
        // the longest designation first stops ACS being read as ACNS.
        usort($candidates, fn (array $a, array $b) => mb_strlen($b['text']) <=> mb_strlen($a['text']));

        foreach ($candidates as $candidate) {
            $needle = $this->fold($candidate['text']);

            // The diploma writes its measures in the plural («as adaptações
            // curriculares significativas») and a teacher's sheet very often
            // writes one child's in the singular. Both forms are tried.
            //
            // Still SUBSTRING, never a loose word match: «adaptacao curricular
            // significativa» is not a substring of «adaptacao curricular NÃO
            // significativa», and that is exactly what stops ACS being found
            // inside an ACNS cell. A subsequence test would lose that.
            if (str_contains($haystack, $needle) || str_contains($this->singularise($haystack), $this->singularise($needle))) {
                $found[$candidate['case']->value] ??= $candidate['case'];
            }
        }

        foreach ($this->tokens($statement) as $token) {
            $entry = $this->dictionary->find($token);

            if ($entry?->code !== null) {
                $found[$entry->code->value] ??= $entry->code;
            }
        }

        return array_values($found);
    }

    private function extractLevel(string $statement): ?SupportMeasureLevel
    {
        $haystack = $this->fold($statement);

        foreach (SupportMeasureLevel::cases() as $case) {
            if (str_contains($haystack, $this->fold($case->label()))) {
                return $case;
            }
        }

        foreach ($this->tokens($statement) as $token) {
            $entry = $this->dictionary->find($token);

            if ($entry !== null && $entry->code === null && $entry->level !== null) {
                return $entry->level;
            }
        }

        return null;
    }

    /**
     * Acronyms that are acronyms and nothing more: either known to the
     * dictionary without a confirmed meaning, or not known at all.
     *
     * A TOKEN DOES NOT BECOME A MEASURE BECAUSE THE CATALOGUE HAPPENS TO KNOW A
     * RELATED CONCEPT. The regime names «o plano individual de transição» at
     * 10.º/4 c), and a column reading «PIT» is still, far more often, the
     * document itself rather than a statement that the measure applies. Turning
     * the sigla into the measure would be the application deciding a legal fact
     * about a child from an abbreviation nobody confirmed.
     *
     * @return list<CodeResolution>
     */
    private function extractUnknownAcronyms(string $statement, InterventionLegalFramework $framework): array
    {
        $resolutions = [];

        foreach ($this->tokens($statement) as $token) {
            $entry = $this->dictionary->find($token);

            if ($entry !== null && ($entry->code !== null || $entry->level !== null)) {
                continue;
            }

            // Recognised as a support or resource. It is understood and it is
            // still not storable: the catalogue has no `resource_support` item,
            // so there is no honest destination, and the preview says so
            // instead of the import filing it under the child's «necessidades».
            if ($entry?->family === CatalogueFamily::SupportResource) {
                $resolutions[] = CodeResolution::resource(
                    rawToken: $entry->token,
                    expansion: $entry->expansion,
                    scope: $entry->scope,
                );

                continue;
            }

            if ($entry !== null && $entry->isConfirmed()) {
                // Known and expandable, but not a measure — PLNM is the case.
                $resolutions[] = CodeResolution::unrecognised(
                    rawToken: $entry->token,
                    scope: $entry->scope,
                    note: (string) __(':token — :expansion. Não é uma medida de suporte.', [
                        'token' => $entry->token,
                        'expansion' => (string) $entry->expansion,
                    ]),
                );

                continue;
            }

            $resolutions[] = CodeResolution::unrecognised(
                rawToken: $token,
                scope: $entry === null ? AcronymScope::Institutional : $entry->scope,
                note: $entry !== null
                    ? (string) __('Sigla reconhecida como sigla; significado por confirmar.')
                    : (string) __('Sigla não reconhecida.'),
            );
        }

        return $resolutions;
    }

    /**
     * Candidate acronyms: runs of 2 to 6 letters in upper case.
     *
     * @return list<string>
     */
    private function tokens(string $statement): array
    {
        preg_match_all('/\b[\p{Lu}]{2,6}\b/u', $statement, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * Case- and accent-insensitive comparison key.
     *
     * Str::ascii, not iconv//TRANSLIT: the latter is locale- and
     * platform-dependent — on Windows it renders «ç» as «c~» — so a measure
     * designation would match on one machine and not on another. A rule about
     * what the law means cannot depend on which server read it.
     */
    private function fold(string $value): string
    {
        return mb_strtolower(Str::ascii($value));
    }

    /**
     * A crude Portuguese singulariser, used ONLY to compare two spellings of
     * the same measure — never to store or display anything.
     *
     * It is orthography, not interpretation: «adaptações curriculares» and
     * «adaptação curricular» name the identical measure, and which one a
     * school's sheet happens to use says nothing about the child. It changes no
     * decision about meaning, so it does not belong to the class of guesses
     * this feature refuses to make.
     */
    private function singularise(string $folded): string
    {
        $words = preg_split('/\s+/u', $folded) ?: [];

        return implode(' ', array_map(function (string $word): string {
            if (str_ends_with($word, 'oes')) {
                return substr($word, 0, -3).'ao';
            }

            if (str_ends_with($word, 'es') && mb_strlen($word) > 4) {
                return substr($word, 0, -2);
            }

            if (str_ends_with($word, 's') && mb_strlen($word) > 3) {
                return substr($word, 0, -1);
            }

            return $word;
        }, $words));
    }
}
