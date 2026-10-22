<?php

namespace Tests\Unit\Assessment\Advanced;

use App\Services\Assessment\Advanced\TimelineComparability;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class TimelineComparabilityTest extends TestCase
{
    /** @return array{percentage_signature: string|null, level_signature: string|null} */
    private function point(?string $percentages, ?string $levels): array
    {
        return ['percentage_signature' => $percentages, 'level_signature' => $levels];
    }

    #[Test]
    public function equal_signatures_are_comparable_and_need_no_excuse(): void
    {
        $this->assertSame(
            ['percentages' => true, 'levels' => true, 'reasons' => []],
            TimelineComparability::between($this->point('p', 'l'), $this->point('p', 'l')),
        );
    }

    #[Test]
    public function different_signatures_are_refused_with_the_reason_of_each(): void
    {
        $verdict = TimelineComparability::between($this->point('p1', 'l1'), $this->point('p2', 'l2'));

        $this->assertFalse($verdict['percentages']);
        $this->assertFalse($verdict['levels']);
        $this->assertSame([TimelineComparability::CRITERIA_DIFFER, TimelineComparability::SCALE_DIFFERS], array_column($verdict['reasons'], 'code'));

        foreach ($verdict['reasons'] as $reason) {
            $this->assertNotSame('', $reason['text']);
        }
    }

    #[Test]
    public function not_knowing_is_never_the_same_as_knowing_even_on_both_sides(): void
    {
        $verdict = TimelineComparability::between($this->point(null, null), $this->point(null, null));

        $this->assertFalse($verdict['percentages']);
        $this->assertFalse($verdict['levels']);
        $this->assertSame([TimelineComparability::CRITERIA_UNKNOWN, TimelineComparability::SCALE_UNKNOWN], array_column($verdict['reasons'], 'code'));

        $oneSide = TimelineComparability::between($this->point('p', null), $this->point('p', 'l'));
        $this->assertTrue($oneSide['percentages']);
        $this->assertFalse($oneSide['levels']);
        $this->assertSame([TimelineComparability::SCALE_UNKNOWN], array_column($oneSide['reasons'], 'code'));
    }

    #[Test]
    public function a_classification_decided_on_another_scale_is_refused_whichever_side_it_is_on(): void
    {
        $this->assertSame(['comparable' => true, 'reason' => null], TimelineComparability::classification(null, null));
        $this->assertSame(['comparable' => true, 'reason' => null], TimelineComparability::classification(['level_comparable' => true], []));

        foreach ([[['level_comparable' => false], null], [null, ['level_comparable' => false]], [['level_comparable' => true], ['level_comparable' => false]]] as [$first, $second]) {
            $verdict = TimelineComparability::classification($first, $second);

            $this->assertFalse($verdict['comparable']);
            $this->assertSame(TimelineComparability::DECIDED_ON_ANOTHER_SCALE, $verdict['reason']['code']);
            $this->assertNotSame('', $verdict['reason']['text']);
        }
    }

    #[Test]
    public function an_unknown_reason_code_is_a_programming_error_and_not_a_blank_text(): void
    {
        $reason = new ReflectionMethod(TimelineComparability::class, 'reason');

        $this->expectException(InvalidArgumentException::class);
        $reason->invoke(null, 'made_up');
    }
}
