<?php

namespace App\Actions\Lessons;

use App\Models\Lesson;
use App\Models\LessonSequence;
use App\Models\LessonSequenceItem;
use App\Models\LessonStatus;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Lessons\LessonSequenceApplicationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Copies a sequence's items onto a class's own upcoming lessons — the core
 * "copy on use" of Fatia 4. Nothing here reads back from the sequence later:
 * once written, each LessonSummary is independent, exactly like a report
 * built from a ReportTemplate.
 */
class ApplyLessonSequence
{
    public function __construct(
        protected SaveLessonSummary $saveLessonSummary,
        protected AuditLog $audit,
    ) {}

    public function execute(
        LessonSequence $sequence,
        SchoolClass $class,
        User $actor,
        LessonCopyOptions $options,
    ): ApplyLessonSequenceResult {
        // The whole gate runs — and can reject — before any Lesson row is
        // touched, including before the transaction opens.
        $this->guardCompatibility($sequence, $class, $actor);

        $items = $sequence->items()->orderBy('position')->get();

        $result = DB::transaction(function () use ($class, $items, $actor, $options): ApplyLessonSequenceResult {
            // Locked here, together, so a concurrent apply of the same or a
            // different sequence cannot pair the same lesson twice.
            $lessons = Lesson::query()
                ->where('class_id', $class->getKey())
                ->where('starts_at', '>=', now())
                ->where('status', '!=', LessonStatus::Taught)
                ->orderBy('starts_at')
                ->limit($items->count())
                ->lockForUpdate()
                ->get();

            $appliedLessonUlids = [];
            $preserved = 0;
            $unavailable = 0;

            foreach ($items as $index => $item) {
                $lesson = $lessons->get($index);

                if ($lesson === null) {
                    // Fewer eligible lessons than items — reported, not thrown.
                    $unavailable++;

                    continue;
                }

                $details = $this->detailsFor($item, $lesson, $options);

                if ($details === []) {
                    // Matched a lesson, but every field was either not
                    // selected, blank at the source, or already non-blank at
                    // the destination — nothing to write. Calling
                    // SaveLessonSummary here would still auto-derive Prepared
                    // and emit an audit event for a lesson that was not
                    // actually touched, so it is skipped entirely.
                    $preserved++;

                    continue;
                }

                $this->saveLessonSummary->execute($lesson, $details, $actor);
                $appliedLessonUlids[] = $lesson->ulid;
            }

            $applied = count($appliedLessonUlids);

            return new ApplyLessonSequenceResult($applied, $preserved, $unavailable, $appliedLessonUlids);
        });

        $this->audit->record(
            'lesson_sequence.applied',
            $sequence,
            $actor,
            'Sequência de aulas aplicada a uma turma.',
            [
                'class_id' => $class->getKey(),
                'items_applied' => $result->applied,
                'items_skipped' => $result->unavailable,
                'copy_options' => $options->toArray(),
            ],
        );

        return $result;
    }

    /**
     * Before anything is written: same organization (defence in depth — the
     * tenancy scope already guarantees it, but this is reachable from user
     * input, not only trusted internal callers), same subject, and — only
     * when the sequence itself declares one — the same grade_level. A
     * sequence with no grade_level is unrestricted by grade, which is what
     * makes it usable in higher education. Finally, the actor must teach the
     * class — the exact same check LessonPolicy::create() already makes,
     * reused rather than reimplemented.
     */
    protected function guardCompatibility(LessonSequence $sequence, SchoolClass $class, User $actor): void
    {
        if ($class->organization_id !== $sequence->organization_id) {
            throw LessonSequenceApplicationException::organizationMismatch();
        }

        if ($class->subject_id !== $sequence->subject_id) {
            throw LessonSequenceApplicationException::subjectMismatch();
        }

        if ($sequence->grade_level !== null && $class->grade_level !== $sequence->grade_level) {
            throw LessonSequenceApplicationException::gradeLevelMismatch();
        }

        Gate::forUser($actor)->authorize('create', [Lesson::class, $class]);
    }

    /**
     * A field is only filled when all three hold: the copy option for it is
     * explicitly selected, the sequence item's value is non-blank, and the
     * destination lesson's current value is blank. Any other combination
     * leaves that field completely untouched — never written, never
     * cleared — so SaveLessonSummary's fill()-based update path (which
     * leaves omitted keys alone) preserves whatever a teacher already wrote
     * by hand. This applies to `content` exactly like the other three: an
     * unchecked "summary" option never results in content being written,
     * even when there is no existing LessonSummary yet.
     *
     * `content` is NOT NULL at the database level, so a lesson with no
     * existing summary that ends up with at least one other field to write
     * still needs a row to hold them — in that one case only, `content` is
     * set to `''` as a structural fallback, never to the sequence item's
     * actual summary text. If nothing at all ends up selected for such a
     * lesson, the returned array is empty and the caller creates no row.
     *
     * @return array{content?: string, private_notes?: ?string, resources?: ?string, homework?: ?string}
     */
    protected function detailsFor(LessonSequenceItem $item, Lesson $lesson, LessonCopyOptions $options): array
    {
        $existing = $lesson->summary()->first();

        $details = [];

        $this->fillField($details, 'content', $options->summary, $item->summary, $existing?->content);
        $this->fillField($details, 'resources', $options->resources, $item->resources, $existing?->resources);
        $this->fillField($details, 'homework', $options->homework, $item->homework, $existing?->homework);
        $this->fillField($details, 'private_notes', $options->privateNotes, $item->private_notes, $existing?->private_notes);

        if ($existing === null && $details !== [] && ! array_key_exists('content', $details)) {
            // No row to hold resources/homework/private_notes without one —
            // this is a placeholder to satisfy the NOT NULL column, not a
            // copy of the sequence item's summary.
            $details['content'] = '';
        }

        return $details;
    }

    /**
     * @param  array<string, string>  $details
     */
    protected function fillField(array &$details, string $key, bool $selected, ?string $sourceValue, ?string $destinationValue): void
    {
        // $sourceValue's own blank check is spelled out here, rather than
        // delegated to isBlank(), so static analysis can see $sourceValue is
        // never null at the assignment below — isBlank() still holds the one
        // canonical definition, used as-is for the destination check.
        if (! $selected || $sourceValue === null || trim($sourceValue) === '' || ! $this->isBlank($destinationValue)) {
            return;
        }

        $details[$key] = $sourceValue;
    }

    protected function isBlank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }
}
