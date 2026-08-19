<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\ReportContext;

/** «Resultados por ano de escolaridade» — see SchoolDimension for the rules. */
class SchoolByYearComposer extends SchoolSectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::SchoolByYear;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        return SchoolDimension::compose($context, $context->fact('by_grade_level'), 'ano de escolaridade');
    }
}
