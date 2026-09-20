<?php

namespace App\Support\Characterisation;

use App\Models\CatalogueFamily;
use App\Models\SupportMeasureCode;
use App\Models\SupportMeasureLevel;

/**
 * The controlled dictionary of acronyms a characterisation import may meet.
 *
 * It has two halves, and the second is the point of the whole class.
 *
 * The CONFIRMED half holds only acronyms this repository can justify. MU, MS and
 * MA are the initials of the three SupportMeasureLevel labels; ACNS and ACS are
 * the initials of two SupportMeasureCode labels; PLNM is a column of the real
 * school export inspected when the roster importer was designed. Nothing here
 * was recalled from memory.
 *
 * The UNCONFIRMED half holds acronyms that turn up in Portuguese schools and
 * whose meaning nobody confirmed *for this project*. They are listed so that the
 * system can say "that is an acronym, I do not know this one" instead of
 * treating it as prose — but they resolve to nothing. The temptation to fill in
 * the obvious expansion is exactly what SupportMeasureCode's own docblock
 * forbids: "inventing pedagogical categories nobody approved is exactly what the
 * project forbids."
 *
 * THE CATALOGUE KNOWING A RELATED CONCEPT IS NOT A REASON TO PROMOTE A TOKEN.
 * The regime names «o plano individual de transição» at article 10.º/4 c), and
 * the catalogue has a case for it — and PIT stays unconfirmed all the same,
 * because a column reading «PIT» is far more often the document a school keeps
 * than a statement that the measure applies to that child. The same holds for
 * RTP and PEI (instruments) and for PLNM (a curricular pathway). What decides a
 * destination is the column's context and the framework, never the fact that a
 * string resembles a catalogue entry.
 *
 * KNOWING THE FAMILY IS NOT THE SAME AS HAVING SOMEWHERE TO PUT IT. CRI is a
 * resource, and is recorded as one — `CatalogueFamily::SupportResource` — even
 * though the catalogue has no `resource_support` item and therefore no
 * structured destination. That is precisely why the family is worth recording:
 * the preview can say «apoio/recurso, sem destino estruturado» instead of
 * filing a Centro de Recursos para a Inclusão under a child's «necessidades»,
 * and a future resources entity can reuse the classification without
 * reinterpreting anybody's prose.
 *
 * Adding an expansion here is a decision, not a typo fix. It needs the same
 * justification as adding a SupportMeasureCode case.
 */
class AcronymDictionary
{
    /** @var array<string, AcronymEntry>|null */
    private ?array $entries = null;

    public function find(string $token): ?AcronymEntry
    {
        return $this->all()[$this->normalise($token)] ?? null;
    }

    public function has(string $token): bool
    {
        return $this->find($token) !== null;
    }

    /**
     * @return array<string, AcronymEntry>
     */
    public function all(): array
    {
        return $this->entries ??= $this->build();
    }

    /**
     * @return array<string, AcronymEntry>
     */
    private function build(): array
    {
        $entries = [];

        foreach ($this->confirmed() as $entry) {
            $entries[$this->normalise($entry->token)] = $entry;
        }

        foreach ($this->knownButUnconfirmed() as $token) {
            $entries[$this->normalise($token)] = new AcronymEntry(
                token: $token,
                expansion: null,
                scope: AcronymScope::National,
            );
        }

        return $entries;
    }

    /**
     * @return list<AcronymEntry>
     */
    private function confirmed(): array
    {
        return [
            // The three levels of Decreto-Lei 54/2018. The expansions are the
            // SupportMeasureLevel labels, verbatim.
            new AcronymEntry('MU', 'Medida universal', AcronymScope::National, level: SupportMeasureLevel::Universal),
            new AcronymEntry('MS', 'Medida seletiva', AcronymScope::National, level: SupportMeasureLevel::Selective),
            new AcronymEntry('MA', 'Medida adicional', AcronymScope::National, level: SupportMeasureLevel::Additional),

            // Two measures whose initials are unambiguous against their labels.
            //
            // NO LEVEL HERE, DELIBERATELY. The level of a measure is what the
            // applicable framework says it is, and writing it down a second
            // time would be a copy that can fall out of step with the diploma
            // the day a measure moves between articles.
            new AcronymEntry(
                'ACNS',
                'Adaptação curricular não significativa',
                AcronymScope::National,
                code: SupportMeasureCode::NonSignificantCurricularAdaptation,
            ),
            new AcronymEntry(
                'ACS',
                'Adaptação curricular significativa',
                AcronymScope::National,
                code: SupportMeasureCode::SignificantCurricularAdaptation,
            ),

            // Not a measure — a column of the school export. Confirmed because
            // the real file carried it (2026-07-28 roster-import design).
            new AcronymEntry('PLNM', 'Português Língua Não Materna', AcronymScope::National),

            // A RESOURCE, AND SAID TO BE ONE. Knowing that CRI is a support
            // rather than a measure is worth recording even though nothing can
            // store it yet: the catalogue has no `resource_support` item, so
            // there is no structured destination, and the honest answer in the
            // preview is «apoio/recurso, sem destino estruturado» rather than
            // quietly filing it under the child's needs.
            new AcronymEntry(
                'CRI',
                'Centro de Recursos para a Inclusão',
                AcronymScope::National,
                family: CatalogueFamily::SupportResource,
            ),
        ];
    }

    /**
     * Acronyms the system recognises AS acronyms and cannot expand.
     *
     * Do not add expansions here. Move a token to confirmed() only with a source
     * this repository can point at.
     *
     * @return list<string>
     */
    private function knownButUnconfirmed(): array
    {
        return ['RTP', 'PEI', 'PIT', 'PEL', 'SPO', 'DEE', 'ATE', 'CAA', 'GAAF'];
    }

    private function normalise(string $token): string
    {
        return mb_strtoupper(trim($token));
    }
}
