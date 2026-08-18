<?php

namespace App\Services\Assessment;

use App\Domain\Assessment\Bc;
use App\Models\InterimAssessment;
use App\Models\SchoolClass;

/**
 * The photograph against where the period actually ended up.
 *
 * TWO SOURCES, AND NEITHER IS RECOMPUTED.
 *
 *  - the interim is READ out of its stored document. Rebuilding it would mean
 *    asking today's data what November looked like, which is the one thing the
 *    whole feature exists to avoid;
 *  - the final is the canonical read model of the period, exactly as Resultados
 *    and the Quadro Síntese show it.
 *
 * The asymmetry is deliberate and worth naming: the interim is FROZEN and the
 * final is LIVE. A period that is still open will move, and a comparison run
 * tomorrow may differ — because the end of the period changed, never because
 * the photograph did. The day LÁPIS gains an official closing, persisting a
 * final snapshot beside the interim would make both sides frozen; nothing here
 * would have to change except where the second side is read from.
 *
 * The arithmetic is one subtraction, in percentage points, at the precision the
 * rest of the application reads at — and the direction is decided by the same
 * comparison BuildResultsProgression already uses, so «subiu» here and «subiu»
 * on Resultados can never mean different things.
 */
class CompareInterimToPeriodFinal
{
    public const PRECISION = BuildResultsProgression::PRECISION;

    public function __construct(protected BuildClassStatistics $statistics) {}

    /**
     * @return array<string, mixed>
     */
    public function compare(SchoolClass $class, InterimAssessment $interim): array
    {
        $snapshot = $interim->snapshot;

        // The period as it stands now — no cutoff, because «final» means
        // everything that counts.
        $final = $this->statistics->for($class, $interim->academicPeriod);

        $students = $this->students($snapshot, $final);

        return [
            'interim' => [
                'ulid' => $interim->ulid,
                // The teacher's own name for the moment, never a label rebuilt
                // from the date (§9 of the naming decision).
                'name' => $interim->name,
                'reference_date' => $interim->reference_date->toDateString(),
                'reference_date_label' => $interim->reference_date->format('d/m/Y'),
            ],
            'period' => [
                'id' => (int) $interim->academic_period_id,
                'label' => (string) $interim->academicPeriod->label,
            ],
            'is_final_still_open' => true,
            'summary' => [
                'interim_average' => $snapshot['summary']['class_average'] ?? null,
                'final_average' => $final['summary']['class_average'] ?? null,
                'change' => $this->change(
                    $snapshot['summary']['class_average'] ?? null,
                    $final['summary']['class_average'] ?? null,
                ),
                'interim_students_with_result' => $snapshot['summary']['students_with_result'] ?? 0,
                'final_students_with_result' => $final['summary']['students_with_result'] ?? 0,
            ],
            'success' => $this->success($snapshot, $final),
            'movement' => $this->movement($students),
            'domains' => $this->domains($snapshot, $final),
            'students' => $students,
        ];
    }

    /**
     * The success rate at both moments.
     *
     * The interim side is READ from its document; the final side comes from the
     * live read model. A photograph taken before the block that added this
     * figure has no `success` in it at all, and says so with nulls rather than
     * with zeros — «não sabemos» and «ninguém passou» are different sentences,
     * and a snapshot is never recomputed to fill the gap (§8, §11).
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $final
     * @return array<string, mixed>
     */
    protected function success(array $snapshot, array $final): array
    {
        $interim = $snapshot['summary']['success'] ?? null;
        $current = $final['summary']['success'] ?? null;

        return [
            'interim' => $interim,
            'final' => $current,
            // Percentage POINTS between the two rates, when both exist.
            'change' => $this->change($interim['rate'] ?? null, $current['rate'] ?? null),
            // «18 → 21», the counts a report will want to quote (§9, §10).
            'interim_succeeded' => $interim['succeeded'] ?? null,
            'final_succeeded' => $current['succeeded'] ?? null,
            'interim_failed' => $interim['failed'] ?? null,
            'final_failed' => $current['failed'] ?? null,
            // Older photographs predate this figure and cannot grow one.
            'interim_is_available' => $interim !== null,
        ];
    }

    /**
     * Per domain, matched by the CANONICAL id.
     *
     * The words come from the photograph — a domain renamed since is still
     * shown under the name it had — and a domain that has since left the profile
     * keeps its row rather than vanishing from its own history (§4).
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $final
     * @return list<array<string, mixed>>
     */
    protected function domains(array $snapshot, array $final): array
    {
        $finalByDomain = [];

        foreach ($final['domain_statistics'] as $row) {
            $finalByDomain[(int) $row['domain_id']] = $row;
        }

        $rows = [];

        foreach ($snapshot['domain_statistics'] ?? [] as $interimDomain) {
            $domainId = (int) $interimDomain['domain_id'];
            $finalDomain = $finalByDomain[$domainId] ?? null;
            unset($finalByDomain[$domainId]);

            $rows[] = [
                'domain_id' => $domainId,
                // Historical words first: this is a reading of the past.
                'label' => $interimDomain['label'],
                'interim_average' => $interimDomain['period_average'],
                'final_average' => $finalDomain['period_average'] ?? null,
                'change' => $this->change($interimDomain['period_average'], $finalDomain['period_average'] ?? null),
                'interim_mention' => $interimDomain['qualitative_band'] ?? null,
                'final_mention' => $finalDomain['qualitative_band'] ?? null,
                'interim_partial_coverage' => (int) ($interimDomain['partial_coverage_count'] ?? 0),
                'final_partial_coverage' => (int) ($finalDomain['partial_coverage_count'] ?? 0),
                // A domain the profile no longer has. Its history is still true.
                'only_in_interim' => $finalDomain === null,
            ];
        }

        // A domain the profile gained after the photograph was taken: it has a
        // present but no past, and saying so is better than hiding it.
        foreach ($finalByDomain as $domainId => $finalDomain) {
            $rows[] = [
                'domain_id' => (int) $domainId,
                'label' => $finalDomain['label'],
                'interim_average' => null,
                'final_average' => $finalDomain['period_average'],
                'change' => null,
                'interim_mention' => null,
                'final_mention' => $finalDomain['qualitative_band'] ?? null,
                'interim_partial_coverage' => 0,
                'final_partial_coverage' => (int) ($finalDomain['partial_coverage_count'] ?? 0),
                'only_in_final' => true,
            ];
        }

        return $rows;
    }

    /**
     * Per student, matched by enrolment.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $final
     * @return list<array<string, mixed>>
     */
    protected function students(array $snapshot, array $final): array
    {
        $finalByEnrollment = [];

        foreach ($final['students'] as $row) {
            $finalByEnrollment[(int) $row['enrollment_id']] = $row;
        }

        $rows = [];

        foreach ($snapshot['students'] ?? [] as $interimStudent) {
            $enrollmentId = (int) $interimStudent['enrollment_id'];
            $finalStudent = $finalByEnrollment[$enrollmentId] ?? null;

            $interimValue = $interimStudent['weighted_average'];
            $finalValue = $finalStudent['weighted_average'] ?? null;

            $rows[] = [
                'enrollment_id' => $enrollmentId,
                // The name as it read then, so a photograph reads like itself.
                'name' => $interimStudent['name_snapshot'],
                'class_number' => $interimStudent['class_number'],
                'interim_average' => $interimValue,
                'final_average' => $finalValue,
                'change' => $this->change($interimValue, $finalValue),
                'direction' => $this->direction($interimValue, $finalValue),
                'interim_band' => $interimStudent['band'],
                'final_band' => $finalStudent['band'] ?? null,
                'interim_coverage_warning' => (bool) ($interimStudent['coverage_warning'] ?? false),
                'final_coverage_warning' => (bool) ($finalStudent['coverage_warning'] ?? false),
                // Kept apart, as they are everywhere else: a decision, an
                // answer the student gave, and a calculated mention are three
                // different statements.
                'interim_classification' => $interimStudent['classification'] ?? null,
                'final_classification' => $finalStudent['classification'] ?? null,
                'interim_self_assessment' => $interimStudent['self_assessment'] ?? null,
                'final_self_assessment' => $finalStudent['self_assessment'] ?? null,
                'domains' => $this->studentDomains($interimStudent, $finalStudent),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $interimStudent
     * @param  array<string, mixed>|null  $finalStudent
     * @return list<array<string, mixed>>
     */
    protected function studentDomains(array $interimStudent, ?array $finalStudent): array
    {
        $finalCells = [];

        foreach ($finalStudent['domains'] ?? [] as $cell) {
            $finalCells[(int) $cell['domain_id']] = $cell;
        }

        $rows = [];

        foreach ($interimStudent['domains'] ?? [] as $cell) {
            $domainId = (int) $cell['domain_id'];
            $finalCell = $finalCells[$domainId] ?? null;

            $rows[] = [
                'domain_id' => $domainId,
                'label' => $cell['label_snapshot'],
                'interim_average' => $cell['weighted_average'],
                'final_average' => $finalCell['weighted_average'] ?? null,
                'change' => $this->change($cell['weighted_average'], $finalCell['weighted_average'] ?? null),
                'interim_mention' => $cell['mention'],
                'final_mention' => $finalCell['mention'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * How many students moved which way.
     *
     * «Sem comparação» is its own answer and never folded into «manteve-se»: a
     * student with no result at one of the two ends did not stand still, they
     * have nothing to stand against (§37 of the statistics decision).
     *
     * @param  list<array<string, mixed>>  $students
     * @return array<string, mixed>
     */
    protected function movement(array $students): array
    {
        $counts = ['progressed' => 0, 'stable' => 0, 'regressed' => 0, 'no_comparison' => 0];
        $changes = [];

        foreach ($students as $student) {
            $counts[match ($student['direction']) {
                'up' => 'progressed',
                'down' => 'regressed',
                'flat' => 'stable',
                default => 'no_comparison',
            }]++;

            if ($student['change'] !== null) {
                $changes[] = (string) $student['change'];
            }
        }

        $total = '0';

        foreach ($changes as $change) {
            $total = Bc::add($total, Bc::of($change));
        }

        return [
            ...$counts,
            'comparable' => count($changes),
            'average_change' => $changes === []
                ? null
                : Bc::round(Bc::div($total, (string) count($changes)), self::PRECISION, 'half_up'),
        ];
    }

    /** Percentage POINTS, or nothing when either end is missing. */
    protected function change(?string $from, ?string $to): ?string
    {
        if ($from === null || $to === null) {
            return null;
        }

        return Bc::round(
            Bc::sub(
                Bc::round(Bc::of($to), self::PRECISION, 'half_up'),
                Bc::round(Bc::of($from), self::PRECISION, 'half_up'),
            ),
            self::PRECISION,
            'half_up',
        );
    }

    /** Judged at the precision that is shown, so «igual» on screen is «igual» here. */
    protected function direction(?string $from, ?string $to): ?string
    {
        if ($from === null || $to === null) {
            return null;
        }

        $comparison = Bc::compare(
            Bc::round(Bc::of($to), self::PRECISION, 'half_up'),
            Bc::round(Bc::of($from), self::PRECISION, 'half_up'),
        );

        return match (true) {
            $comparison > 0 => 'up',
            $comparison < 0 => 'down',
            default => 'flat',
        };
    }
}
