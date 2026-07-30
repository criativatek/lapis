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
     * Edits an instrument and diffs its items against what already exists —
     * unlike the naive delete-all-and-recreate this replaced, an existing item
     * is updated in place (its id, and any scores against it, survive). An item
     * is only ever removed when the client stops sending its `ulid` back, and
     * only when it has no score recorded — otherwise the whole update is
     * rejected before anything is written. Lowering a scored item's
     * points_possible below its highest recorded points_earned is rejected the
     * same way. Both rejection checks run BEFORE the transaction starts, so a
     * rejected update never partially applies.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array{ulid?: ?string, code: string, label?: ?string, points_possible: float, is_bonus?: bool, domains?: list<array{domain_id: int, allocation_percent: float}>}>  $items
     */
    public function update(Instrument $instrument, array $attributes, array $items): Instrument
    {
        $this->guard($attributes, $items);

        $existingItems = $instrument->items()->get()->keyBy('ulid');
        $submittedUlids = collect($items)->pluck('ulid')->filter()->all();
        $toRemove = $existingItems->reject(
            fn (InstrumentItem $item) => in_array($item->ulid, $submittedUlids, true),
        );

        foreach ($toRemove as $item) {
            if ($item->scores()->exists()) {
                throw InstrumentValidationException::cannotRemoveScoredItem($item->code);
            }
        }

        foreach ($items as $itemData) {
            if (! isset($itemData['ulid'])) {
                continue;
            }

            $existing = $existingItems->get($itemData['ulid']);

            if ($existing === null) {
                continue;
            }

            $highestScore = $existing->scores()->max('points_earned');

            if ($highestScore !== null && (float) $itemData['points_possible'] < (float) $highestScore) {
                throw InstrumentValidationException::pointsPossibleBelowExistingScore(
                    $existing->code,
                    rtrim(rtrim(number_format((float) $highestScore, 4, '.', ''), '0'), '.'),
                );
            }
        }

        return DB::transaction(function () use ($instrument, $attributes, $items, $toRemove, $existingItems): Instrument {
            $instrument->update($attributes);

            foreach ($toRemove as $item) {
                $item->delete();
            }

            // Two kept/edited items can swap codes in the same update (A -> B,
            // B -> A). MySQL has no deferred unique constraints, so writing
            // either one's real final code straight away can collide with the
            // other's still-current code. Every kept/edited item is first
            // parked under a code derived from its own (always-unique) id
            // before any of them receives its real final code — that way no
            // intermediate state can ever collide with the unique constraint
            // on (instrument_id, code).
            foreach ($items as $itemData) {
                if (! isset($itemData['ulid'])) {
                    continue;
                }

                $existing = $existingItems->get($itemData['ulid']);

                if ($existing !== null) {
                    $existing->update(['code' => '__tmp_'.$existing->id]);
                }
            }

            foreach ($items as $index => $itemData) {
                $existing = isset($itemData['ulid']) ? $existingItems->get($itemData['ulid']) : null;

                if ($existing !== null) {
                    $existing->update([...$this->itemAttributes($itemData), 'sequence' => $index + 1]);
                    $existing->domainAllocations()->each(fn ($allocation) => $allocation->delete());
                    $this->syncAllocations($existing, $itemData['domains'] ?? []);
                } else {
                    $created = $instrument->items()->create([...$this->itemAttributes($itemData), 'sequence' => $index + 1]);
                    $this->syncAllocations($created, $itemData['domains'] ?? []);
                }
            }

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
            $created = $instrument->items()->create([...$this->itemAttributes($item), 'sequence' => $index + 1]);
            $this->syncAllocations($created, $item['domains'] ?? []);
        }
    }

    /**
     * The plain-column attributes shared by a freshly-created item and an
     * edited existing one — kept in one place so create() and update() never
     * drift on which fields an item carries.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function itemAttributes(array $item): array
    {
        return [
            'code' => $item['code'],
            'label' => $item['label'] ?? null,
            'points_possible' => $item['points_possible'],
            'scoring_mode' => $item['scoring_mode'] ?? 'points',
            'is_bonus' => $item['is_bonus'] ?? false,
            'source_group_label' => $item['source_group_label'] ?? null,
        ];
    }

    /**
     * @param  list<array{domain_id: int, allocation_percent: float}>  $domains
     */
    protected function syncAllocations(InstrumentItem $item, array $domains): void
    {
        foreach ($domains as $allocation) {
            $item->domainAllocations()->create([
                'domain_id' => $allocation['domain_id'],
                'allocation_percent' => $allocation['allocation_percent'],
            ]);
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
