<?php

namespace App\Services\Import\Correction;

use App\Domain\Import\Correction\CanonicalCorrectionGrid;
use App\Domain\Import\Correction\CanonicalGroup;
use App\Domain\Import\Correction\CanonicalInstrument;
use App\Domain\Import\Correction\CanonicalItem;
use App\Domain\Import\Correction\CanonicalResult;
use App\Domain\Import\Correction\CanonicalStudent;
use App\Domain\Import\Correction\CanonicalSummary;
use App\Domain\Import\Correction\CorrectionGridSource;
use App\Domain\Import\Correction\ImportIssue;
use App\Domain\Import\Correction\IssueCode;
use App\Domain\Import\Correction\IssueSeverity;
use App\Models\ResultState;

/**
 * Reads a stored grid back into objects.
 *
 * The wizard spans several requests, so what the teacher reviewed has to be the
 * same thing that later gets written — and it has to survive without the
 * uploaded file, which is deleted the moment it stops being needed. The
 * database, not the upload, is the source of truth between steps.
 *
 * Missing keys become nulls rather than defaults. A snapshot written by an older
 * version simply says less; it never says something invented.
 */
class CanonicalCorrectionGridSnapshot
{
    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function rehydrate(array $snapshot): CanonicalCorrectionGrid
    {
        return new CanonicalCorrectionGrid(
            source: CorrectionGridSource::tryFrom((string) ($snapshot['source'] ?? '')) ?? CorrectionGridSource::Generic,
            instrument: self::instrument((array) ($snapshot['instrument'] ?? [])),
            groups: array_map(self::group(...), array_values((array) ($snapshot['groups'] ?? []))),
            items: array_map(self::item(...), array_values((array) ($snapshot['items'] ?? []))),
            students: array_map(self::student(...), array_values((array) ($snapshot['students'] ?? []))),
            results: array_map(self::result(...), array_values((array) ($snapshot['results'] ?? []))),
            summaries: array_map(self::summary(...), array_values((array) ($snapshot['summaries'] ?? []))),
            issues: array_map(self::issue(...), array_values((array) ($snapshot['issues'] ?? []))),
            sourceMetadata: (array) ($snapshot['source_metadata'] ?? []),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected static function instrument(array $row): CanonicalInstrument
    {
        return new CanonicalInstrument(
            title: self::text($row['title'] ?? null),
            appliedOn: self::text($row['applied_on'] ?? null),
            externalId: self::text($row['external_id'] ?? null),
            sourceTotal: self::text($row['source_total'] ?? null),
            sourcePercentage: self::text($row['source_percentage'] ?? null),
            metadata: (array) ($row['metadata'] ?? []),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected static function group(array $row): CanonicalGroup
    {
        return new CanonicalGroup(
            sourceKey: (string) ($row['source_key'] ?? ''),
            sequence: (int) ($row['sequence'] ?? 1),
            label: self::text($row['label'] ?? null),
            externalId: self::text($row['external_id'] ?? null),
            metadata: (array) ($row['metadata'] ?? []),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected static function item(array $row): CanonicalItem
    {
        return new CanonicalItem(
            sourceKey: (string) ($row['source_key'] ?? ''),
            sequence: (int) ($row['sequence'] ?? 1),
            groupSourceKey: self::text($row['group_source_key'] ?? null),
            externalId: self::text($row['external_id'] ?? null),
            code: self::text($row['code'] ?? null),
            label: self::text($row['label'] ?? null),
            questionText: self::text($row['question_text'] ?? null),
            pointsPossible: self::text($row['points_possible'] ?? null),
            answerKey: self::text($row['answer_key'] ?? null),
            domainHint: self::text($row['domain_hint'] ?? null),
            metadata: (array) ($row['metadata'] ?? []),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected static function student(array $row): CanonicalStudent
    {
        return new CanonicalStudent(
            sourceKey: (string) ($row['source_key'] ?? ''),
            externalId: self::text($row['external_id'] ?? null),
            cardNumber: self::text($row['card_number'] ?? null),
            classNumber: isset($row['class_number']) ? (int) $row['class_number'] : null,
            displayName: self::text($row['display_name'] ?? null),
            sourceScore: self::text($row['source_score'] ?? null),
            sourceCorrect: isset($row['source_correct']) ? (int) $row['source_correct'] : null,
            sourceAnswered: isset($row['source_answered']) ? (int) $row['source_answered'] : null,
            metadata: (array) ($row['metadata'] ?? []),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected static function result(array $row): CanonicalResult
    {
        return new CanonicalResult(
            studentSourceKey: (string) ($row['student_source_key'] ?? ''),
            itemSourceKey: (string) ($row['item_source_key'] ?? ''),
            rawResponse: self::text($row['raw_response'] ?? null),
            // Read back exactly as stored: a null here is "not determined" and
            // must never be rounded up into a zero on the way through (§18).
            pointsEarned: self::text($row['points_earned'] ?? null),
            resultState: isset($row['result_state']) && is_string($row['result_state'])
                ? ResultState::tryFrom($row['result_state'])
                : null,
            isCorrect: isset($row['is_correct']) ? (bool) $row['is_correct'] : null,
            sourceValue: self::text($row['source_value'] ?? null),
            metadata: (array) ($row['metadata'] ?? []),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected static function summary(array $row): CanonicalSummary
    {
        return new CanonicalSummary(
            scope: (string) ($row['scope'] ?? CanonicalSummary::SCOPE_STUDENT),
            key: (string) ($row['key'] ?? ''),
            subjectSourceKey: self::text($row['subject_source_key'] ?? null),
            value: self::text($row['value'] ?? null),
            unit: self::text($row['unit'] ?? null),
            metadata: (array) ($row['metadata'] ?? []),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected static function issue(array $row): ImportIssue
    {
        $code = IssueCode::tryFrom((string) ($row['code'] ?? '')) ?? IssueCode::AmbiguousValue;

        return new ImportIssue(
            code: $code,
            severity: IssueSeverity::tryFrom((string) ($row['severity'] ?? '')) ?? $code->defaultSeverity(),
            message: (string) ($row['message'] ?? ''),
            context: (array) ($row['context'] ?? []),
        );
    }

    protected static function text(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
