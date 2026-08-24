<?php

namespace App\Actions\Lessons;

use App\Models\LessonSequence;
use App\Models\LessonSequenceItem;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Create-or-replace for a sequence and its ordered items, in one transaction.
 *
 * Items are diffed against what already exists rather than deleted and
 * recreated wholesale — an item the client stops sending is removed, one it
 * keeps sending (identified by ulid) is updated in place, and anything
 * without a recognised ulid is new. Position is never trusted from the
 * client beyond ordering: it is always (re)written from the item's index in
 * the submitted array, matching InstrumentBuilder's own item-replace idiom.
 *
 * This never touches a LessonSummary. Items are a template; only
 * ApplyLessonSequence copies their text into a lesson, and only at the
 * moment it is asked to.
 */
class SaveLessonSequence
{
    public function __construct(protected AuditLog $audit) {}

    /**
     * @param  array<string, mixed>  $attributes  name, subject_id, academic_year_id, grade_level
     * @param  list<array{ulid?: ?string, summary: string, private_notes?: ?string, resources?: ?string, homework?: ?string}>  $items
     */
    public function execute(?LessonSequence $sequence, array $attributes, array $items, User $actor): LessonSequence
    {
        $sequence = DB::transaction(function () use ($sequence, $attributes, $items, $actor): LessonSequence {
            $sequence ??= new LessonSequence(['user_id' => $actor->getKey()]);
            $sequence->fill($attributes);
            $sequence->save();

            $existingItems = $sequence->items()->get()->keyBy('ulid');
            $submittedUlids = collect($items)->pluck('ulid')->filter()->all();

            $existingItems
                ->reject(fn (LessonSequenceItem $item) => in_array($item->ulid, $submittedUlids, true))
                ->each(fn (LessonSequenceItem $item) => $item->delete());

            foreach ($items as $index => $itemData) {
                $existing = isset($itemData['ulid']) ? $existingItems->get($itemData['ulid']) : null;
                $itemAttributes = [
                    'summary' => $itemData['summary'],
                    'private_notes' => $itemData['private_notes'] ?? null,
                    'resources' => $itemData['resources'] ?? null,
                    'homework' => $itemData['homework'] ?? null,
                    'position' => $index + 1,
                ];

                if ($existing !== null) {
                    $existing->update($itemAttributes);
                } else {
                    $sequence->items()->create($itemAttributes);
                }
            }

            return $sequence;
        });

        $this->audit->record(
            'lesson_sequence.saved',
            $sequence,
            $actor,
            'Sequência de aulas guardada.',
            ['items_count' => count($items)],
        );

        return $sequence->refresh();
    }
}
