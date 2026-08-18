<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\ReportContext;

/**
 * One section, generated.
 *
 * ONE CLASS PER SECTION, and shared sections shared rather than duplicated:
 * `difficulties` is the same composer for a class report and an individual one,
 * branching on the report's type where the sentence genuinely differs. That is
 * the whole point of a common core (§3) — four report types, one machine.
 *
 * A COMPOSER IS A PURE FUNCTION of its context. No queries, no clock, no
 * randomness: the same report generated twice produces the same words, which is
 * what makes «regenerar apenas esta secção» safe and what makes the whole thing
 * testable (§44).
 *
 * RETURNING NOTHING IS A VALID ANSWER, and the common one. A composer that
 * cannot say anything truthful returns ComposedSection::empty() and the section
 * disappears from the document. Filling silence is how a generated report
 * starts lying (§41, §67).
 */
interface SectionComposer
{
    public function key(): SectionKey;

    public function compose(ReportContext $context): ComposedSection;
}
