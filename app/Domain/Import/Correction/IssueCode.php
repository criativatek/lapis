<?php

namespace App\Domain\Import\Correction;

/**
 * The stable identity of a problem found while reading a correction grid.
 *
 * A code, not a sentence. The message is written for the teacher and will be
 * rewritten; the code is what tests assert on, what the UI branches on, and what
 * survives translation. Anything that inspects an issue by matching its text is
 * a bug waiting for the day somebody improves the wording.
 */
enum IssueCode: string
{
    /** The source gives no marks for a question and none has been chosen yet. */
    case MissingPoints = 'missing_points';

    /** A row in the file matches nobody in the class. */
    case UnknownStudent = 'unknown_student';

    /** A row in the file matches more than one student, so nobody may decide but the teacher. */
    case AmbiguousStudent = 'ambiguous_student';

    /** A question in the file has not been pointed at a question in the instrument. */
    case UnmappedItem = 'unmapped_item';

    /** A cell that could be read more than one way — never resolved by guessing. */
    case AmbiguousValue = 'ambiguous_value';

    /** The source's own total disagrees with what LÁPIS computes from the same elements. */
    case SourceTotalMismatch = 'source_total_mismatch';

    /** The file's shape is not something this parser can read honestly. */
    case UnsupportedStructure = 'unsupported_structure';

    /** The source carries a result for a student the instrument does not apply to (§11.4). */
    case NotApplicableStudent = 'not_applicable_student';

    /** A student in the class has no row in the file. Left unassessed — never zeroed. */
    case StudentMissingFromSource = 'student_missing_from_source';

    /** The source recorded no answer at all, which is not the same as a wrong answer. */
    case UnansweredQuestion = 'unanswered_question';

    /** A mark already exists and the file carries a different one. */
    case ScoreConflict = 'score_conflict';

    /** This exact file has been used in an import before. */
    case DuplicateFile = 'duplicate_file';

    public function defaultSeverity(): IssueSeverity
    {
        return match ($this) {
            self::UnsupportedStructure,
            self::AmbiguousStudent,
            self::MissingPoints => IssueSeverity::Error,

            self::UnknownStudent,
            self::UnmappedItem,
            self::AmbiguousValue,
            self::SourceTotalMismatch,
            self::NotApplicableStudent,
            self::ScoreConflict,
            self::DuplicateFile => IssueSeverity::Warning,

            self::StudentMissingFromSource,
            self::UnansweredQuestion => IssueSeverity::Info,
        };
    }
}
