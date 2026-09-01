<?php

namespace Tests\Unit\Assessment;

use App\Support\Assessment\GradeLevelLabel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The one place a profile's grade levels are joined into pt-PT display copy
 * ("7.º", "7.º e 8.º", "7.º, 8.º e 9.º") — backend-owned so the frontend never
 * reimplements this joining logic with its own, possibly divergent, copy.
 */
class GradeLevelLabelTest extends TestCase
{
    #[Test]
    public function an_empty_list_is_an_empty_string(): void
    {
        $this->assertSame('', GradeLevelLabel::forList([]));
    }

    #[Test]
    public function a_single_grade_level_is_shown_alone(): void
    {
        $this->assertSame('7.º', GradeLevelLabel::forList(['7.º']));
    }

    #[Test]
    public function two_grade_levels_are_joined_with_e(): void
    {
        $this->assertSame('7.º e 8.º', GradeLevelLabel::forList(['7.º', '8.º']));
    }

    #[Test]
    public function three_grade_levels_use_commas_and_a_final_e(): void
    {
        $this->assertSame('7.º, 8.º e 9.º', GradeLevelLabel::forList(['7.º', '8.º', '9.º']));
    }

    #[Test]
    public function the_presentation_order_given_is_preserved_rather_than_resorted(): void
    {
        // The controller hands this a pre-sorted list (AssessmentProfile::gradeLevels
        // orders by grade_level); the helper's job is joining, not sorting, so an
        // out-of-order input is joined exactly as given.
        $this->assertSame('9.º, 7.º e 8.º', GradeLevelLabel::forList(['9.º', '7.º', '8.º']));
    }
}
