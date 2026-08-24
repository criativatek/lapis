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

            foreach ($items as $index => $item) {
                $lesson = $lessons->get($index);

                if ($lesson === null) {
                    // Fewer eligible lessons than items — reported, not thrown.
                    continue;
                }

                $this->saveLessonSummary->execute($lesson, $this->detailsFor($item, $lesson, $options), $actor);
                $appliedLessonUlids[] = $lesson->ulid;
            }

            $applied = count($appliedLessonUlids);

            return new ApplyLessonSequenceResult($applied, $items->count() - $applied, $appliedLessonUlids);
        });

        $this->audit->record(
            'lesson_sequence.applied',
            $sequence,
            $actor,
            'Sequência de aulas aplicada a uma turma.',
            [
                'class_id' => $class->getKey(),
                'items_applied' => $result->applied,
                'items_skipped' => $result->skipped,
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
     * Content is NOT NULL at the database level, so a lesson with no summary
     * yet always receives it — otherwise SaveLessonSummary's create path
     * would be handed no content at all. A lesson that already has a summary
     * only has its content overwritten when the "summary" option is
     * explicitly on; otherwise the key is omitted entirely, and
     * SaveLessonSummary's fill()-based update path leaves whatever is
     * already there completely untouched. The other three fields are
     * nullable, so they follow the plain "include only if selected" rule.
     *
     * @return array{content?: string, private_notes?: ?string, resources?: ?string, homework?: ?string}
     */
    protected function detailsFor(LessonSequenceItem $item, Lesson $lesson, LessonCopyOptions $options): array
    {
        $details = [];
        $hasExistingSummary = $lesson->summary()->exists();

        if ($options->summary || ! $hasExistingSummary) {
            $details['content'] = $item->summary;
        }

        if ($options->resources) {
            $details['resources'] = $item->resources;
        }

        if ($options->homework) {
            $details['homework'] = $item->homework;
        }

        if ($options->privateNotes) {
            $details['private_notes'] = $item->private_notes;
        }

        return $details;
    }
}
