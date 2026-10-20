<?php

namespace Tests\Unit\Reporting;

use App\Services\Reporting\ClassDocuments\InstrumentGridLayout;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The orientation and the blocks of columns of an instrument's printed grid.
 *
 * Pure function, so these tests need no database. What they hold is the promise
 * the document depends on: dompdf cannot paginate horizontally, so a column that
 * does not fit is lost without a word — the plan must NEVER drop a question, and
 * must never ask a block to carry more than the page is wide.
 *
 * The numbers worked out by hand, in millimetres: portrait leaves 170 − 7 − 40 =
 * 123 for columns, so 12 questions fit a block and, beside the usual summary
 * (16 + 14 + 18 = 48), 7; landscape leaves 210, so 22 and 17.
 */
class InstrumentGridLayoutTest extends TestCase
{
    /** Pontuação bruta, resultado e nível. */
    private const SUMMARY = [16.0, 14.0, 18.0];

    /**
     * @return list<int|null>
     */
    private function ungrouped(int $count): array
    {
        return array_fill(0, $count, null);
    }

    /**
     * @param  array{blocks: list<array{items: list<int>, summary: list<int>}>}  $plan
     * @return list<int>
     */
    private function itemsOf(array $plan): array
    {
        return array_merge(...array_map(fn (array $block): array => $block['items'], $plan['blocks']));
    }

    #[Test]
    public function a_short_instrument_is_one_portrait_block_with_its_summary(): void
    {
        $plan = InstrumentGridLayout::plan($this->ungrouped(3), self::SUMMARY);

        $this->assertSame(InstrumentGridLayout::PORTRAIT, $plan['orientation']);
        $this->assertSame([['items' => [0, 1, 2], 'summary' => [0, 1, 2]]], $plan['blocks']);
    }

    #[Test]
    public function seven_questions_are_the_most_that_stay_portrait_beside_the_usual_summary(): void
    {
        $seven = InstrumentGridLayout::plan($this->ungrouped(7), self::SUMMARY);
        $eight = InstrumentGridLayout::plan($this->ungrouped(8), self::SUMMARY);

        $this->assertSame(InstrumentGridLayout::PORTRAIT, $seven['orientation']);
        $this->assertCount(1, $seven['blocks']);
        $this->assertSame(InstrumentGridLayout::LANDSCAPE, $eight['orientation']);
    }

    #[Test]
    public function seventeen_questions_are_the_most_that_stay_one_landscape_block(): void
    {
        $seventeen = InstrumentGridLayout::plan($this->ungrouped(17), self::SUMMARY);
        $eighteen = InstrumentGridLayout::plan($this->ungrouped(18), self::SUMMARY);

        $this->assertSame(InstrumentGridLayout::LANDSCAPE, $seventeen['orientation']);
        $this->assertCount(1, $seventeen['blocks']);
        $this->assertCount(2, $eighteen['blocks']);
    }

    #[Test]
    public function forty_questions_become_three_even_landscape_blocks_and_the_summary_rides_in_the_last(): void
    {
        $plan = InstrumentGridLayout::plan($this->ungrouped(40), self::SUMMARY);

        $this->assertSame(InstrumentGridLayout::LANDSCAPE, $plan['orientation']);
        $this->assertSame([14, 13, 13], array_map(fn (array $block): int => count($block['items']), $plan['blocks']));
        $this->assertSame([[], [], [0, 1, 2]], array_map(fn (array $block): array => $block['summary'], $plan['blocks']));
        $this->assertSame(range(0, 39), $this->itemsOf($plan));
    }

    #[Test]
    public function sixty_questions_never_ask_a_block_for_more_than_it_can_carry(): void
    {
        $plan = InstrumentGridLayout::plan($this->ungrouped(60), self::SUMMARY);

        $this->assertSame(range(0, 59), $this->itemsOf($plan));
        $this->assertSame([21, 22, 17], array_map(fn (array $block): int => count($block['items']), $plan['blocks']));
    }

    #[Test]
    public function a_forced_orientation_replaces_the_automatic_one_and_the_blocks_follow_its_width(): void
    {
        $portrait = InstrumentGridLayout::plan($this->ungrouped(40), self::SUMMARY, InstrumentGridLayout::PORTRAIT);
        $landscape = InstrumentGridLayout::plan($this->ungrouped(3), self::SUMMARY, InstrumentGridLayout::LANDSCAPE);

        $this->assertSame(InstrumentGridLayout::PORTRAIT, $portrait['orientation']);
        $this->assertSame(range(0, 39), $this->itemsOf($portrait));
        $this->assertCount(4, $portrait['blocks']);

        foreach ($portrait['blocks'] as $index => $block) {
            $limit = $index === 3 ? 7 : 12;
            $this->assertLessThanOrEqual($limit, count($block['items']));
        }

        $this->assertSame(InstrumentGridLayout::LANDSCAPE, $landscape['orientation']);
        $this->assertCount(1, $landscape['blocks']);
    }

    #[Test]
    public function a_cut_lands_on_a_group_boundary_when_one_is_near(): void
    {
        // 14 questions of one group, then 16 of another: the even cut would fall at
        // 15, one past the end of the first group.
        $groups = [...array_fill(0, 14, 10), ...array_fill(0, 16, 20)];

        $plan = InstrumentGridLayout::plan($groups, self::SUMMARY);

        $this->assertSame(InstrumentGridLayout::LANDSCAPE, $plan['orientation']);
        $this->assertSame([range(0, 13), range(14, 29)], array_map(fn (array $block): array => $block['items'], $plan['blocks']));
    }

    #[Test]
    public function a_group_longer_than_a_block_is_split_and_nothing_is_lost(): void
    {
        $plan = InstrumentGridLayout::plan(array_fill(0, 30, 10), self::SUMMARY, InstrumentGridLayout::PORTRAIT);

        $this->assertSame(range(0, 29), $this->itemsOf($plan));
        $this->assertGreaterThan(1, count($plan['blocks']));
    }

    #[Test]
    public function a_summary_too_wide_to_sit_together_is_split_column_by_column_into_blocks_of_its_own(): void
    {
        // Twelve domains of 12 mm: 144 mm, more than the 123 mm a portrait block has.
        $summary = array_fill(0, 12, 12.0);

        $plan = InstrumentGridLayout::plan($this->ungrouped(5), $summary, InstrumentGridLayout::PORTRAIT);

        $placed = array_merge(...array_map(fn (array $block): array => $block['summary'], $plan['blocks']));

        $this->assertSame(range(0, 4), $this->itemsOf($plan));
        $this->assertSame(range(0, 11), $placed);

        foreach ($plan['blocks'] as $block) {
            $this->assertLessThanOrEqual(InstrumentGridLayout::roomFor(InstrumentGridLayout::PORTRAIT), count($block['summary']) * 12.0);
        }
    }

    #[Test]
    public function without_summary_columns_the_questions_fill_the_blocks_on_their_own(): void
    {
        $plan = InstrumentGridLayout::plan($this->ungrouped(25), [], InstrumentGridLayout::LANDSCAPE);

        $this->assertSame(range(0, 24), $this->itemsOf($plan));
        $this->assertCount(2, $plan['blocks']);
        $this->assertSame([[], []], array_map(fn (array $block): array => $block['summary'], $plan['blocks']));
    }

    #[Test]
    public function an_empty_instrument_still_gets_one_block_and_a_lone_summary_keeps_its_columns(): void
    {
        $this->assertSame([['items' => [], 'summary' => []]], InstrumentGridLayout::plan([], [])['blocks']);
        $this->assertSame([['items' => [], 'summary' => [0, 1, 2]]], InstrumentGridLayout::plan([], self::SUMMARY)['blocks']);
    }

    /**
     * @return iterable<string, array{0: int, 1: string, 2: list<float>}>
     */
    public static function everySize(): iterable
    {
        foreach ([InstrumentGridLayout::AUTO, InstrumentGridLayout::PORTRAIT, InstrumentGridLayout::LANDSCAPE] as $orientation) {
            foreach ([[], self::SUMMARY, [16.0, 14.0, 18.0, 12.0, 12.0, 12.0]] as $summary) {
                foreach (range(1, 90) as $count) {
                    yield "{$count} questions, ".count($summary)." summary columns, {$orientation}" => [$count, $orientation, $summary];
                }
            }
        }
    }

    /**
     * @param  list<float>  $summary
     */
    #[Test]
    #[DataProvider('everySize')]
    public function no_question_and_no_summary_column_is_ever_lost_and_no_block_overflows(int $count, string $orientation, array $summary): void
    {
        // Two groups, to exercise the boundary search as well.
        $groups = array_map(fn (int $i): int => $i < intdiv($count, 3) ? 1 : 2, range(0, $count - 1));

        $plan = InstrumentGridLayout::plan($groups, $summary, $orientation);

        $this->assertSame(range(0, $count - 1), $this->itemsOf($plan), 'every question exactly once, in order');
        $this->assertSame(
            array_keys($summary),
            array_merge(...array_map(fn (array $block): array => $block['summary'], $plan['blocks'])),
            'every summary column exactly once, in order',
        );

        $room = InstrumentGridLayout::roomFor($plan['orientation']);

        foreach ($plan['blocks'] as $block) {
            $width = count($block['items']) * InstrumentGridLayout::ITEM_WIDTH
                + array_sum(array_map(fn (int $index): float => $summary[$index], $block['summary']));

            // A single column wider than the page cannot be helped; nothing else may exceed it.
            if (count($block['items']) + count($block['summary']) > 1) {
                $this->assertLessThanOrEqual($room, $width, 'a block must fit the page');
            }
        }
    }
}
