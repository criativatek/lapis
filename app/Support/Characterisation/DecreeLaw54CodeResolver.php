<?php

namespace App\Support\Characterisation;

use App\Models\SupportMeasureCode;
use App\Models\SupportMeasureLevel;
use App\Support\Interventions\LegalFrameworkResolver;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Reads a cell against the Decreto-Lei 54/2018 enums this repository already
 * carries.
 *
 * The rules it enforces, in the order they matter:
 *
 * 1. A sub-paragraph letter — "a)", "b)", "e)" — NEVER becomes a measure. There
 *    is no letter-to-measure mapping anywhere in this repository, and writing
 *    one from memory would be inventing it. Letters travel as annotations,
 *    visible and stored verbatim, and they drag the result down to ambiguous
 *    when nothing else in the cell resolved.
 * 2. A named measure ("ACNS", or the enum's own label) resolves on its own, and
 *    a level token beside it is corroboration, not a requirement. This is why
 *    "MS b) + ACNS" is recognised while a bare "b)" is not.
 * 3. A level with no measure is ambiguous, not recognised: knowing the level
 *    says nothing about which measure was meant.
 * 4. A level that contradicts the measure's own level is ambiguous, whatever
 *    the measure was. Two sources disagreeing is not evidence, it is a reason
 *    to ask.
 */
class DecreeLaw54CodeResolver implements LegalCodeResolver
{
    public function __construct(
        private readonly AcronymDictionary $dictionary,
        private readonly LegalFrameworkResolver $frameworks,
        private readonly CurrentOrganization $organization,
    ) {}

    public function resolveCell(string $cell, ?SupportMeasureLevel $columnLevel = null): array
    {
        if (! $this->frameworkApplies()) {
            // The organization's jurisdiction has no legal taxonomy — or is one
            // Lapispro has never encoded. «MU» is then just two letters, and
            // reading it as a Portuguese measure level would apply one
            // country's law to another country's school. Everything in the cell
            // reaches the preview unrecognised, for a person to deal with.
            return array_map(
                fn (string $statement) => CodeResolution::unrecognised(
                    rawToken: $statement,
                    note: __('Não há enquadramento legal aplicável a esta organização, por isso os códigos não são interpretados.'),
                ),
                $this->splitStatements($cell),
            );
        }

        $resolutions = [];

        foreach ($this->splitStatements($cell) as $statement) {
            foreach ($this->resolveStatement($statement, $columnLevel) as $resolution) {
                $resolutions[] = $resolution;
            }
        }

        return $resolutions;
    }

    /**
     * Whether the organization this import belongs to has a legal taxonomy at
     * all.
     *
     * The date is today's, and that is the one place this differs from
     * interventions, deliberately. An intervention is read under the law in
     * force when it STARTED; an import is somebody typing, now, what their
     * school's current paperwork says — so the framework that matters is the
     * one in force as they type.
     */
    private function frameworkApplies(): bool
    {
        if (! $this->organization->isResolved()) {
            return false;
        }

        return $this->frameworks->for($this->organization->get(), Carbon::now())->hasLegalTaxonomy();
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
    private function resolveStatement(string $statement, ?SupportMeasureLevel $columnLevel): array
    {
        $annotations = $this->extractAnnotations($statement);
        $codes = $this->extractCodes($statement);
        $statedLevel = $this->extractLevel($statement) ?? $columnLevel;

        if ($codes !== []) {
            return array_map(
                fn (SupportMeasureCode $code) => $this->forCode($statement, $code, $statedLevel, $annotations),
                $codes,
            );
        }

        $unknown = $this->extractUnknownAcronyms($statement);

        if ($statedLevel !== null) {
            // The level is known, the measure is not. Honest answer: ambiguous.
            return array_merge(
                [CodeResolution::ambiguous(
                    rawToken: $statement,
                    level: $statedLevel,
                    unresolvedAnnotations: $annotations,
                    note: $annotations === []
                        ? __('Nível identificado, medida por identificar.')
                        : __('Nível identificado; a alínea não identifica a medida.'),
                )],
                $unknown,
            );
        }

        if ($annotations !== []) {
            // §13, the canonical case: "b)" with no idea which level's b).
            return array_merge(
                [CodeResolution::ambiguous(
                    rawToken: $statement,
                    unresolvedAnnotations: $annotations,
                    note: __('Alínea sem nível: não é possível saber a que medida se refere.'),
                )],
                $unknown,
            );
        }

        if ($unknown !== []) {
            return $unknown;
        }

        return [CodeResolution::unrecognised(
            rawToken: $statement,
            note: __('Sem código reconhecido.'),
        )];
    }

    /**
     * @param  list<string>  $annotations
     */
    private function forCode(
        string $statement,
        SupportMeasureCode $code,
        ?SupportMeasureLevel $statedLevel,
        array $annotations,
    ): CodeResolution {
        if ($statedLevel !== null && $statedLevel !== $code->level()) {
            return CodeResolution::ambiguous(
                rawToken: $statement,
                level: null,
                unresolvedAnnotations: $annotations,
                note: __('O nível indicado (:stated) não corresponde ao da medida :code (:actual).', [
                    'stated' => $statedLevel->label(),
                    'code' => $code->label(),
                    'actual' => $code->level()->label(),
                ]),
            );
        }

        return CodeResolution::recognised(
            rawToken: $statement,
            code: $code,
            unresolvedAnnotations: $annotations,
            note: $annotations === []
                ? null
                : (string) __('A alínea foi lida mas não identifica a medida; fica registada tal como veio.'),
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
     * @return list<SupportMeasureCode>
     */
    private function extractCodes(string $statement): array
    {
        $found = [];
        $haystack = $this->fold($statement);

        // Full labels first — "Adaptação curricular significativa" contains the
        // non-significant one's words but not the other way round, so matching
        // the longest label first avoids reading ACS as ACNS.
        $cases = SupportMeasureCode::cases();
        usort($cases, fn ($a, $b) => mb_strlen($b->label()) <=> mb_strlen($a->label()));

        foreach ($cases as $case) {
            if (str_contains($haystack, $this->fold($case->label()))) {
                $found[$case->value] = $case;
            }
        }

        foreach ($this->tokens($statement) as $token) {
            $entry = $this->dictionary->find($token);

            if ($entry?->resolvesToMeasure()) {
                $found[$entry->code->value] = $entry->code;
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
     * @return list<CodeResolution>
     */
    private function extractUnknownAcronyms(string $statement): array
    {
        $resolutions = [];

        foreach ($this->tokens($statement) as $token) {
            $entry = $this->dictionary->find($token);

            if ($entry !== null && ($entry->resolvesToMeasure() || $entry->level !== null)) {
                continue;
            }

            if ($entry !== null && $entry->isConfirmed()) {
                // Known and expandable, but not a measure — PLNM is the case.
                $resolutions[] = CodeResolution::unrecognised(
                    rawToken: $entry->token,
                    scope: $entry->scope,
                    note: __(':token — :expansion. Não é uma medida de suporte.', [
                        'token' => $entry->token,
                        'expansion' => $entry->expansion,
                    ]),
                );

                continue;
            }

            $resolutions[] = CodeResolution::unrecognised(
                rawToken: $token,
                scope: $entry === null ? AcronymScope::Institutional : $entry->scope,
                note: $entry !== null
                    ? __('Sigla reconhecida como sigla; significado por confirmar.')
                    : __('Sigla não reconhecida.'),
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
     * label would match on one machine and not on another. A rule about what
     * the law means cannot depend on which server read it.
     */
    private function fold(string $value): string
    {
        return mb_strtolower(Str::ascii($value));
    }
}
