<?php

namespace App\Actions\DataImports;

use App\Actions\DataImports\Concerns\ResolvesWrittenReferences;
use App\Models\Classification;
use App\Models\ClassificationStatus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes the classifications of a validated backup — the ordinary ones as they
 * come, and the CHAINS of rectified ones in the one order the table accepts.
 *
 * A rectified cell is more than one row: the decisions that were replaced
 * (`superseded`) and the one that replaced them, pointing at each other. The
 * table refuses every way of writing that looks natural, and SQLite — where the
 * suite runs — refuses none of them, which is how this went unnoticed:
 *
 *  - `classifications_superseded_pointer_check`: a row is `superseded` if and
 *    only if it has `superseded_by_id`. A superseded row cannot be inserted
 *    before its successor exists;
 *  - `classifications_rectification_complete_check` plus the foreign key: the
 *    successor cannot be inserted before the row it rectifies exists;
 *  - `classifications_one_live_unique`: one row that is not superseded per
 *    (enrollment, period, scope) — a successor cannot be inserted while the row
 *    it replaces is still alive.
 *
 * So each chain is written from the oldest row to the newest, with the SAME
 * sequence `RectifyClassification` follows when a teacher rectifies a grade:
 *
 *   the oldest goes in published, alone and alive, with no pointer;
 *   then, for each next row:
 *     (a) the previous one becomes superseded, its pointer set to ITSELF —
 *         temporary: it satisfies the pointer CHECK and takes the row out of the
 *         unique index, the only two things that can be done to it at this point;
 *     (b) the next goes in, already pointing back at the previous one;
 *     (c) the previous one's pointer is moved to its real successor.
 *
 * Every row but the last goes in as `published` whatever the backup says, because
 * it is the live one of its cell for exactly as long as it takes the next to
 * arrive; the last goes in with its own status. No self-pointer outlives (c), and
 * the end of the chain checks it. The whole thing runs inside the import's
 * transaction: a chain either arrives complete or not at all.
 */
class WriteClassifications
{
    use ResolvesWrittenReferences;

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $enrollmentsByUlid
     * @param  array<string, int>  $periodsByUlid
     * @param  array<string, int>  $profileVersionsByUlid
     * @param  array<string, int>  $scalesByRef
     * @param  array<string, int>  $scaleLevelsByRef
     * @return int how many rows were written
     */
    public function write(array $rows, array $enrollmentsByUlid, array $periodsByUlid, array $profileVersionsByUlid, array $scalesByRef, array $scaleLevelsByRef): int
    {
        $created = 0;
        /** @var array<string, array<string, mixed>> $chained */
        $chained = [];

        foreach ($rows as $row) {
            if ($row['classification'] !== 'new') {
                continue;
            }

            if ($row['superseded_by_ulid'] !== null || $row['rectifies_ulid'] !== null) {
                $chained[(string) $row['ulid']] = $row;

                continue;
            }

            $attributes = $this->attributes($row, $enrollmentsByUlid, $periodsByUlid, $profileVersionsByUlid, $scalesByRef, $scaleLevelsByRef);

            if ($attributes === null) {
                continue;
            }

            $this->insert($attributes);
            $created++;
        }

        foreach ($chained as $head) {
            if ($head['rectifies_ulid'] === null) {
                $created += $this->writeChain($head, $chained, $enrollmentsByUlid, $periodsByUlid, $profileVersionsByUlid, $scalesByRef, $scaleLevelsByRef);
            }
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $head  the oldest row of the chain
     * @param  array<string, array<string, mixed>>  $chained  every chained row of the import, by ulid
     * @param  array<string, int>  $enrollmentsByUlid
     * @param  array<string, int>  $periodsByUlid
     * @param  array<string, int>  $profileVersionsByUlid
     * @param  array<string, int>  $scalesByRef
     * @param  array<string, int>  $scaleLevelsByRef
     */
    private function writeChain(array $head, array $chained, array $enrollmentsByUlid, array $periodsByUlid, array $profileVersionsByUlid, array $scalesByRef, array $scaleLevelsByRef): int
    {
        $members = [];
        $at = $head;

        while ($at !== null) {
            if (isset($members[(string) $at['ulid']])) {
                throw new RuntimeException('Cadeia de retificações circular.');
            }

            $members[(string) $at['ulid']] = $at;
            $next = $at['superseded_by_ulid'];
            $at = $next === null ? null : ($chained[$next] ?? throw new RuntimeException('Cadeia de retificações incompleta.'));
        }

        $members = array_values($members);
        $last = count($members) - 1;
        /** @var list<int> $written */
        $written = [];
        $previous = null;

        foreach ($members as $index => $row) {
            $attributes = $this->attributes($row, $enrollmentsByUlid, $periodsByUlid, $profileVersionsByUlid, $scalesByRef, $scaleLevelsByRef);

            // The enrollment is the same all along the chain: either all of it
            // can be placed or none of it, exactly as for any other row.
            if ($attributes === null) {
                return 0;
            }

            if ($index < $last) {
                $attributes['status'] = ClassificationStatus::Published->value;
            }

            if ($previous !== null) {
                // (a) The previous row steps aside: superseded, pointing at itself.
                Classification::query()->whereKey($previous)->update([
                    'status' => ClassificationStatus::Superseded->value,
                    'superseded_by_id' => $previous,
                    'lock_version' => DB::raw('lock_version + 1'),
                ]);
                $attributes['rectifies_id'] = $previous;
            }

            // (b) This one goes in — the live row of the cell.
            $id = $this->insert($attributes);
            $written[] = $id;

            if ($previous !== null) {
                // (c) The pointer takes its real value.
                Classification::query()->whereKey($previous)->update(['superseded_by_id' => $id]);
            }

            $previous = $id;
        }

        $this->assertWholeAndLinear($written);

        return count($written);
    }

    /**
     * What the table would only half-say: no row points at itself, and exactly
     * one row of the chain — the last — is still alive.
     *
     * @param  list<int>  $ids
     */
    private function assertWholeAndLinear(array $ids): void
    {
        if (Classification::query()->whereIn('id', $ids)->whereColumn('superseded_by_id', 'id')->exists()) {
            throw new RuntimeException('Uma classificação ficou a apontar para si própria.');
        }

        if (Classification::query()->whereIn('id', $ids)->where('status', '!=', ClassificationStatus::Superseded->value)->count() !== 1) {
            throw new RuntimeException('A cadeia de retificações não ficou com uma só classificação em vigor.');
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $enrollmentsByUlid
     * @param  array<string, int>  $periodsByUlid
     * @param  array<string, int>  $profileVersionsByUlid
     * @param  array<string, int>  $scalesByRef
     * @param  array<string, int>  $scaleLevelsByRef
     * @return array<string, mixed>|null null when the enrollment is not one this import placed
     */
    private function attributes(array $row, array $enrollmentsByUlid, array $periodsByUlid, array $profileVersionsByUlid, array $scalesByRef, array $scaleLevelsByRef): ?array
    {
        $enrollmentId = $this->resolveId($row['enrollment_ulid'], $enrollmentsByUlid);

        if ($enrollmentId === null) {
            return null;
        }

        return [
            'ulid' => $this->writableUlid($row), 'enrollment_id' => $enrollmentId,
            'academic_period_id' => $this->resolveId($row['academic_period_ulid'], $periodsByUlid),
            'scope' => $row['scope'], 'assessment_profile_version_id' => $this->resolveId($row['assessment_profile_version_ulid'], $profileVersionsByUlid),
            'status' => $row['status'], 'proposed_normalized_value' => $row['proposed_normalized_value'],
            'proposed_value' => $row['proposed_value'], 'proposed_scale_level_id' => $this->resolveScaleLevelId($row['proposed_scale_level'], $scalesByRef, $scaleLevelsByRef),
            'final_value' => $row['final_value'], 'final_scale_level_id' => $this->resolveScaleLevelId($row['final_scale_level'], $scalesByRef, $scaleLevelsByRef),
            'override_reason' => $row['override_reason'], 'overridden_by' => $row['overridden_by'], 'overridden_at' => $row['overridden_at'],
            'confirmed_by' => $row['confirmed_by'], 'confirmed_at' => $row['confirmed_at'], 'published_at' => $row['published_at'],
            // The «rectifies» pointer is set by the chain, to the id the previous
            // row got here; the four facts that go with it come from the file.
            'rectification_reason' => $row['rectification_reason'], 'rectification_note' => $row['rectification_note'],
            'rectified_by' => $row['rectified_by'], 'rectified_at' => $row['rectified_at'],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return int the id of the new row
     */
    private function insert(array $attributes): int
    {
        $classification = new Classification;
        $classification->forceFill($attributes);
        $classification->save();

        return (int) $classification->getKey();
    }
}
