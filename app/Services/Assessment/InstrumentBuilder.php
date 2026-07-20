<?php

namespace App\Services\Assessment;

use App\Models\Domain;
use App\Models\Instrument;
use App\Models\InstrumentItem;
use App\Models\SchoolClass;
use App\Support\Assessment\InstrumentValidationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Creates and edits an instrument together with its items and their domain
 * allocations, in one transaction (§12.3).
 *
 * Two rules live here because they span rows and so cannot be CHECK constraints:
 *  - each item's domain allocations sum to 100% (or the item has none at all);
 *  - the items' points sum to the declared total, unless bonus is allowed.
 */
class InstrumentBuilder
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array{code: string, label?: ?string, points_possible: float, domains?: list<array{domain_id: int, allocation_percent: float}>, is_bonus?: bool}>  $items
     */
    public function create(SchoolClass $class, array $attributes, array $items): Instrument
    {
        $this->guard($attributes, $items);

        return DB::transaction(function () use ($class, $attributes, $items): Instrument {
            $instrument = $class->instruments()->create($attributes);

            $this->syncItems($instrument, $items);

            return $instrument;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $items
     */
    public function update(Instrument $instrument, array $attributes, array $items): Instrument
    {
        $this->guard($attributes, $items);

        return DB::transaction(function () use ($instrument, $attributes, $items): Instrument {
            $instrument->update($attributes);

            // ponytail: replace-all while no scores exist. student_item_scores has
            // a RESTRICT FK to items, so once the teacher has marked anything this
            // fails loud instead of quietly deleting marks — which is when this
            // must become a real diff.
            $instrument->items()->each(fn (InstrumentItem $item) => $item->delete());
            $this->syncItems($instrument->refresh(), $items);

            return $instrument->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $items
     */
    protected function guard(array $attributes, array $items): void
    {
        if ($items === []) {
            throw InstrumentValidationException::noItems();
        }

        foreach ($items as $index => $item) {
            $allocations = $item['domains'] ?? [];

            // No allocation at all is legitimate — the item then counts only
            // toward the instrument total, not toward any domain (§4.3).
            if ($allocations === []) {
                continue;
            }

            $total = array_sum(array_column($allocations, 'allocation_percent'));

            if (abs($total - 100.0) > 0.0001) {
                throw InstrumentValidationException::allocationsMustTotal100(
                    (string) ($item['code'] ?? $index + 1),
                    rtrim(rtrim(number_format($total, 4, '.', ''), '0'), '.'),
                );
            }
        }

        $declaredTotal = $attributes['total_points'] ?? null;
        $allowBonus = (bool) ($attributes['allow_bonus'] ?? false);

        if ($declaredTotal !== null && ! $allowBonus) {
            $itemTotal = 0.0;
            foreach ($items as $item) {
                if (! ($item['is_bonus'] ?? false)) {
                    $itemTotal += (float) $item['points_possible'];
                }
            }

            if (abs($itemTotal - (float) $declaredTotal) > 0.0001) {
                throw InstrumentValidationException::pointsDoNotMatchTotal(
                    rtrim(rtrim(number_format($itemTotal, 4, '.', ''), '0'), '.'),
                    rtrim(rtrim(number_format((float) $declaredTotal, 4, '.', ''), '0'), '.'),
                );
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    protected function syncItems(Instrument $instrument, array $items): void
    {
        foreach ($items as $index => $item) {
            $created = $instrument->items()->create([
                'code' => $item['code'],
                'label' => $item['label'] ?? null,
                'sequence' => $index + 1,
                'points_possible' => $item['points_possible'],
                'scoring_mode' => $item['scoring_mode'] ?? 'points',
                'is_bonus' => $item['is_bonus'] ?? false,
                'source_group_label' => $item['source_group_label'] ?? null,
            ]);

            foreach ($item['domains'] ?? [] as $allocation) {
                $created->domainAllocations()->create([
                    'domain_id' => $allocation['domain_id'],
                    'allocation_percent' => $allocation['allocation_percent'],
                ]);
            }
        }
    }

    /**
     * The domains a class's active profile version assesses — the only domains an
     * item may be allocated to.
     *
     * @return Collection<int, Domain>
     */
    public function domainsFor(SchoolClass $class): Collection
    {
        $version = $class->profileVersion;

        if ($version === null) {
            return collect();
        }

        return Domain::whereIn('id', $version->domains()->pluck('domain_id'))->orderBy('sequence')->get();
    }
}
