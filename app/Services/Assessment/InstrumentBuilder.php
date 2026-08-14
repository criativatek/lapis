<?php

namespace App\Services\Assessment;

use App\Models\Domain;
use App\Models\Instrument;
use App\Models\InstrumentGroup;
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
     * @param  list<array<string, mixed>>  $groups
     */
    public function create(SchoolClass $class, array $attributes, array $items, array $groups = []): Instrument
    {
        $this->guard($attributes, $items, $groups);

        $attributes = $this->applyDiagnosticDefault($attributes);

        return DB::transaction(function () use ($class, $attributes, $items, $groups): Instrument {
            $instrument = $class->instruments()->create($attributes);

            $this->syncItems($instrument, $items, $this->syncGroups($instrument, $groups));

            return $instrument;
        });
    }

    /**
     * A diagnostic instrument the teacher never made an explicit choice for
     * defaults to not counting toward the classification — never a persisted
     * zero, never applied to formative/summative. array_key_exists(), not a
     * falsy check: `counts_toward_classification` genuinely absent from the
     * request is what "never made a choice" means here, and is exactly what
     * InstrumentRequest's 'sometimes' rule leaves out of validated() —
     * indistinguishable from an explicit `false`, which this must respect
     * exactly rather than overwrite. create() only: an existing instrument's
     * purpose changing on update never silently rewrites its own
     * already-persisted counts_toward_classification.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function applyDiagnosticDefault(array $attributes): array
    {
        if (($attributes['purpose'] ?? null) === 'diagnostic' && ! array_key_exists('counts_toward_classification', $attributes)) {
            $attributes['counts_toward_classification'] = false;
        }

        return $attributes;
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
     * @param  list<array<string, mixed>>  $groups
     */
    public function update(Instrument $instrument, array $attributes, array $items, array $groups = []): Instrument
    {
        $this->guard($attributes, $items, $groups);

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

        return DB::transaction(function () use ($instrument, $attributes, $items, $groups, $toRemove, $existingItems): Instrument {
            $instrument->update($attributes);

            // Items leave before groups are reconciled, so a group emptied in
            // this same edit can be removed rather than reported as occupied.
            foreach ($toRemove as $item) {
                $item->delete();
            }

            $groupsByIndex = $this->syncGroups($instrument, $groups);

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
                    $existing->update([
                        ...$this->itemAttributes($itemData),
                        'instrument_group_id' => $this->groupIdFor($itemData, $groupsByIndex),
                        'sequence' => $index + 1,
                    ]);
                    $existing->domainAllocations()->each(fn ($allocation) => $allocation->delete());
                    $this->syncAllocations($existing, $itemData['domains'] ?? []);
                } else {
                    $created = $instrument->items()->create([
                        ...$this->itemAttributes($itemData),
                        'instrument_group_id' => $this->groupIdFor($itemData, $groupsByIndex),
                        'sequence' => $index + 1,
                    ]);
                    $this->syncAllocations($created, $itemData['domains'] ?? []);
                }
            }

            return $instrument->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $groups
     */
    protected function guard(array $attributes, array $items, array $groups = []): void
    {
        if ($items === []) {
            throw InstrumentValidationException::noItems();
        }

        // A code identifies a question WITHIN ITS GROUP, so Oralidade/Q2 and
        // Gramática/Q2 are two different questions and both are legitimate.
        // Two Q2 in the same group are not. UNIQUE(instrument_group_id, code)
        // enforces it in the database; catching it here makes it a message
        // instead of a 500, and covers every caller, not just the Form Request.
        // Case-insensitively, matching the column's collation.
        $seenPerGroup = [];

        foreach ($items as $item) {
            $code = mb_strtolower(trim((string) ($item['code'] ?? '')));

            if ($code === '') {
                continue;
            }

            $groupIndex = (int) ($item['group_index'] ?? 0);

            if (isset($seenPerGroup[$groupIndex][$code])) {
                throw InstrumentValidationException::duplicateItemCodeInGroup(
                    trim((string) $item['code']),
                    $groups[$groupIndex]['label'] ?? null,
                );
            }

            $seenPerGroup[$groupIndex][$code] = true;
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
     * @param  non-empty-array<int, InstrumentGroup>  $groupsByIndex
     */
    protected function syncItems(Instrument $instrument, array $items, array $groupsByIndex): void
    {
        foreach ($items as $index => $item) {
            $created = $instrument->items()->create([
                ...$this->itemAttributes($item),
                'instrument_group_id' => $this->groupIdFor($item, $groupsByIndex),
                'sequence' => $index + 1,
            ]);
            $this->syncAllocations($created, $item['domains'] ?? []);
        }
    }

    /**
     * Brings an instrument's groups in line with what was submitted, and
     * returns them keyed by the index items refer to.
     *
     * An instrument with no groups submitted still gets one — the implicit,
     * unnamed group. That is what lets a simple instrument stay simple: the
     * teacher never creates a group, never sees one, and the structure exists
     * only so a question always has somewhere to belong.
     *
     * @param  list<array<string, mixed>>  $groups
     * @return non-empty-array<int, InstrumentGroup>
     */
    protected function syncGroups(Instrument $instrument, array $groups): array
    {
        $existing = $instrument->groups()->get()->keyBy('ulid');

        if ($groups === []) {
            // Reuse the instrument's own first group rather than creating a
            // second one on every save.
            $implicit = $instrument->groups()->orderBy('sequence')->first()
                ?? $instrument->groups()->create(['label' => null, 'sequence' => 1]);

            return [0 => $implicit];
        }

        // A ulid that is not this instrument's is ignored rather than adopted:
        // the group is then treated as new. The controller already strips
        // foreign ulids; this is the same rule enforced for every caller.
        $kept = [];

        foreach ($groups as $index => $group) {
            $ulid = $group['ulid'] ?? null;
            $current = $ulid === null ? null : $existing->get($ulid);

            if ($current !== null) {
                $kept[$index] = $current;
            }
        }

        // Groups the client stopped sending go FIRST, so a sequence they were
        // occupying is free before anything tries to claim it — and never with
        // questions still inside (§21). The FK is RESTRICT; this is the
        // readable error rather than a database one.
        foreach ($existing as $group) {
            if (in_array($group->getKey(), array_map(fn ($g) => $g->getKey(), $kept), true)) {
                continue;
            }

            if ($group->items()->exists()) {
                throw InstrumentValidationException::cannotRemoveGroupWithItems($group->label ?? '');
            }

            $group->delete();
        }

        // Reordering two groups (A:1,B:2 → B:1,A:2) would otherwise write A's
        // new sequence 2 while B still holds it, and UNIQUE(instrument_id,
        // sequence) refuses. MySQL has no deferred constraints, so every kept
        // group is first parked on a sequence far above any real one, and only
        // then given its final position — no intermediate state can collide.
        // Same two-phase trick already used for item codes below.
        $parkingOffset = 10000;

        foreach ($kept as $index => $group) {
            $group->update(['sequence' => $parkingOffset + $index]);
        }

        $resolved = [];

        foreach ($groups as $index => $group) {
            $current = $kept[$index] ?? null;

            if ($current !== null) {
                // Renaming a group changes its label and nothing else — its id
                // and ulid are the identity, so every question stays put.
                $current->update(['label' => $group['label'] ?? null, 'sequence' => $index + 1]);
                $resolved[$index] = $current;

                continue;
            }

            $resolved[$index] = $instrument->groups()->create([
                'label' => $group['label'] ?? null,
                'sequence' => $index + 1,
            ]);
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  non-empty-array<int, InstrumentGroup>  $groupsByIndex
     */
    protected function groupIdFor(array $item, array $groupsByIndex): int
    {
        $index = (int) ($item['group_index'] ?? 0);

        if (isset($groupsByIndex[$index])) {
            return $groupsByIndex[$index]->id;
        }

        // An item pointing at a group that was not submitted falls back to the
        // first one, which for a simple instrument is the implicit group.
        // syncGroups() always returns at least that group, so this is never
        // an empty array.
        return array_values($groupsByIndex)[0]->id;
    }

    /**
     * The plain-column attributes shared by a freshly-created item and an
     * edited existing one — kept in one place so create() and update() never
     * drift on which fields an item carries.
     *
     * source_group_label is deliberately absent: it records where an imported
     * question came from, and the edit form neither shows nor sends it. Listing
     * it here with a `?? null` default meant every manual edit silently wiped
     * the provenance of an imported instrument. It is written only on create,
     * by the caller that actually knows it.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function itemAttributes(array $item): array
    {
        $attributes = [
            'code' => $item['code'],
            'label' => $item['label'] ?? null,
            'points_possible' => $item['points_possible'],
            'scoring_mode' => $item['scoring_mode'] ?? 'points',
            'is_bonus' => $item['is_bonus'] ?? false,
        ];

        // Only when the caller says something about it — absent means "leave
        // whatever is already there", not "clear it".
        if (array_key_exists('source_group_label', $item)) {
            $attributes['source_group_label'] = $item['source_group_label'];
        }

        return $attributes;
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
