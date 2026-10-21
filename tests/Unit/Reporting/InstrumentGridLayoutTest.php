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

    /**
     * Every plan the original planner (before the item width became a parameter)
     * drew for a wide sweep of questions, groups, summaries and orientations, as
     * one text. `tests/Fixtures/EvaluationSheet/layout-sweep.sha256` holds the
     * hash of what THAT code produced; this one must still produce it.
     */
    private function sweep(): string
    {
        $lines = [];

        foreach ([InstrumentGridLayout::AUTO, InstrumentGridLayout::PORTRAIT, InstrumentGridLayout::LANDSCAPE] as $orientation) {
            foreach ([[], self::SUMMARY, [16.0, 14.0, 18.0, 12.0, 12.0, 12.0], [30.0, 30.0, 30.0, 30.0, 30.0, 30.0, 30.0, 30.0]] as $summary) {
                foreach ([0, 4, 7, 11] as $groupSize) {
                    foreach (range(0, 90) as $count) {
                        $groups = array_map(fn (int $i): ?int => $groupSize === 0 ? null : intdiv($i, $groupSize), $count === 0 ? [] : range(0, $count - 1));
                        $plan = InstrumentGridLayout::plan($groups, $summary, $orientation);

                        $lines[] = $orientation.'|'.count($summary).'|'.$groupSize.'|'.$count.'|'.$plan['orientation'].'|'.json_encode($plan['blocks']);
                    }
                }
            }
        }

        return implode("\n", $lines)."\n";
    }

    #[Test]
    public function the_default_item_width_plans_exactly_what_the_planner_always_planned(): void
    {
        $path = __DIR__.'/../../Fixtures/EvaluationSheet/layout-sweep.sha256';

        $this->assertFileExists($path);
        $this->assertSame(trim((string) file_get_contents($path)), hash('sha256', $this->sweep()));
    }

    #[Test]
    public function saying_the_default_item_width_out_loud_changes_nothing(): void
    {
        foreach ([InstrumentGridLayout::AUTO, InstrumentGridLayout::PORTRAIT, InstrumentGridLayout::LANDSCAPE] as $orientation) {
            foreach ([7, 22, 40, 61] as $count) {
                $this->assertSame(
                    InstrumentGridLayout::plan($this->ungrouped($count), self::SUMMARY, $orientation),
                    InstrumentGridLayout::plan($this->ungrouped($count), self::SUMMARY, $orientation, InstrumentGridLayout::ITEM_WIDTH),
                );
            }
        }
    }

    #[Test]
    public function wider_items_need_more_blocks_and_narrower_ones_fewer(): void
    {
        $blocks = fn (float $width): int => count(InstrumentGridLayout::plan($this->ungrouped(66), [], InstrumentGridLayout::LANDSCAPE, $width)['blocks']);

        // Landscape leaves 257 − 7 − 40 = 210 mm of room: 22 items of 9.5 mm to a block, 7 of 30 mm, 52 of 4 mm.
        $this->assertSame(3, $blocks(InstrumentGridLayout::ITEM_WIDTH));
        $this->assertSame(10, $blocks(30.0));
        $this->assertSame(2, $blocks(4.0));
    }

    #[Test]
    public function a_chosen_item_width_never_drops_an_item_and_never_overflows_a_block(): void
    {
        foreach ([4.0, 9.5, 20.0, 30.0, 61.0, 150.0, 400.0] as $itemWidth) {
            foreach ([InstrumentGridLayout::AUTO, InstrumentGridLayout::PORTRAIT, InstrumentGridLayout::LANDSCAPE] as $orientation) {
                foreach ([1, 5, 12, 33] as $count) {
                    $plan = InstrumentGridLayout::plan($this->ungrouped($count), self::SUMMARY, $orientation, $itemWidth);

                    $this->assertSame(range(0, $count - 1), $this->itemsOf($plan), "{$count} items of {$itemWidth} mm, {$orientation}");
                    $this->assertSame([0, 1, 2], array_merge(...array_map(fn (array $block): array => $block['summary'], $plan['blocks'])));

                    $room = InstrumentGridLayout::roomFor($plan['orientation']);

                    foreach ($plan['blocks'] as $block) {
                        $width = count($block['items']) * $itemWidth
                            + array_sum(array_map(fn (int $index): float => self::SUMMARY[$index], $block['summary']));

                        // A block always takes at least one item, so an item that cannot sit beside the
                        // summary on the page (or is wider than the page) cannot be helped, as before;
                        // wherever one can, nothing may exceed the page.
                        if ($itemWidth + array_sum(self::SUMMARY) <= $room) {
                            $this->assertLessThanOrEqual($room, $width);
                        }
                    }
                }
            }
        }
    }

    #[Test]
    public function a_summary_that_fits_a_page_but_leaves_no_room_for_an_item_gets_a_block_of_its_own(): void
    {
        // Portrait leaves 123 mm. A summary of 115 mm fits a page, but only 8 mm
        // would be left beside it: not even one 9.5 mm question. It must not ride in
        // the last block of questions (that block would be 124.5 mm wide and dompdf
        // would lose what passes the margin); it stands alone, after the questions.
        $plan = InstrumentGridLayout::plan($this->ungrouped(5), [60.0, 55.0], InstrumentGridLayout::PORTRAIT);

        $this->assertSame([['items' => [0, 1, 2, 3, 4], 'summary' => []], ['items' => [], 'summary' => [0, 1]]], $plan['blocks']);

        // One millimetre less and an item fits beside it again: it rides, as it always did.
        $rides = InstrumentGridLayout::plan($this->ungrouped(5), [60.0, 53.0], InstrumentGridLayout::PORTRAIT);

        $last = $rides['blocks'][count($rides['blocks']) - 1];

        $this->assertSame([0, 1], $last['summary']);
        $this->assertNotSame([], $last['items']);
    }

    #[Test]
    public function a_summary_alone_with_no_items_stays_together_whatever_the_item_width(): void
    {
        $this->assertSame([['items' => [], 'summary' => [0, 1]]], InstrumentGridLayout::plan([], [60.0, 55.0], InstrumentGridLayout::PORTRAIT, 30.0)['blocks']);
    }
}
