<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\ReportContext;

/** «Resultados por disciplina» — see SchoolDimension for the rules. */
class SchoolBySubjectComposer extends SchoolSectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::SchoolBySubject;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        return SchoolDimension::compose($context, $context->fact('by_subject'), 'disciplina');
    }
}
