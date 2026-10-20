<?php

namespace Tests\Unit\Reporting;

use App\Models\InterventionStatus;
use App\Services\Reporting\ClassDocuments\MeasureMapColumn;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which column of the Mapa de Medidas Educativas a measure's state belongs to.
 *
 * The decision is written down once, in a match with no default, and these
 * assertions pin it for every state that exists today.
 */
class MeasureMapColumnTest extends TestCase
{
    #[Test]
    public function a_planned_measure_is_to_be_implemented(): void
    {
        $this->assertSame(MeasureMapColumn::Planned, MeasureMapColumn::forStatus(InterventionStatus::New));
    }

    #[Test]
    public function an_in_progress_concluded_or_suspended_measure_is_implemented(): void
    {
        $this->assertSame(MeasureMapColumn::Implemented, MeasureMapColumn::forStatus(InterventionStatus::InProgress));
        $this->assertSame(MeasureMapColumn::Implemented, MeasureMapColumn::forStatus(InterventionStatus::Concluded));
        $this->assertSame(MeasureMapColumn::Implemented, MeasureMapColumn::forStatus(InterventionStatus::Suspended));
    }

    #[Test]
    public function a_cancelled_measure_is_on_neither_column(): void
    {
        $this->assertNull(MeasureMapColumn::forStatus(InterventionStatus::Cancelled));
    }

    #[Test]
    public function every_state_is_decided_and_each_column_lists_exactly_its_own_states(): void
    {
        $listed = [
            ...MeasureMapColumn::Implemented->statuses(),
            ...MeasureMapColumn::Planned->statuses(),
        ];

        $this->assertEqualsCanonicalizing(
            [InterventionStatus::New, InterventionStatus::InProgress, InterventionStatus::Concluded, InterventionStatus::Suspended],
            $listed,
        );
    }
}
