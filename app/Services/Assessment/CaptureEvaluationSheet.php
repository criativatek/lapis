<?php

namespace App\Services\Assessment;

use App\Models\AcademicPeriod;
use App\Models\ClassificationScope;
use App\Models\EvaluationSheetExport;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Assessment\DomainColorPalette;
use App\Support\Assessment\EvaluationSheetException;
use App\Support\Hashing\CanonicalPayload;
use Illuminate\Support\Carbon;

/**
 * Takes the photograph of a Pauta de Avaliação.
 *
 * NOTHING IS CALCULATED HERE. The numbers come from BuildEvaluationSheet — the
 * very same read model, the very same figures the teacher was looking at when
 * they pressed the button. What this adds is the act of keeping it, and the
 * historical identity that keeps it readable after the live configuration has
 * moved on.
 *
 * THE PAYLOAD HAS TO BE ENOUGH ON ITS OWN. Opening a snapshot must never join
 * anything live: not the class, not the period, not the domains, not the
 * colours. That is why the labels travel as literal copies (§15). A school that
 * renames «1.º Semestre» to «1.º Período» in February has not changed what
 * December's pauta said, and the record must go on saying it.
 *
 * The colour travels for the same reason. It is presentation, but it is the
 * presentation that was on screen; a snapshot repainted by today's palette is
 * a different document from the one the teacher kept.
 */
class CaptureEvaluationSheet
{
    public const CURRENT_VERSION = 1;

    public function __construct(
        protected BuildEvaluationSheet $builder,
    ) {}

    public function capture(
        SchoolClass $class,
        AcademicPeriod $period,
        ClassificationScope $scope,
        string $momentLabel,
        Carbon $effectiveAt,
        User $author,
    ): EvaluationSheetExport {
        $this->guardTheDate($class, $period, $effectiveAt);

        // ONE build, exactly as the screen does it, with the colour resolved
        // through the single seam both sides share.
        $sheet = $this->builder->for($class, $period, $scope);
        $domains = DomainColorPalette::decorate($sheet['domains']);
        $students = $sheet['students'];
        $warnings = $this->warnings($domains, $students);

        $payload = [
            'version' => self::CURRENT_VERSION,
            'scope' => $scope->value,
            'class' => [
                'label' => (string) $class->label,
                'subject' => (string) $class->subject->name,
                'academic_year' => (string) $class->academicYear->label,
            ],
            // CRITICAL. Read once, here, and never again: the history screen
            // must not go looking up what this period is called today.
            'period' => [
                'label' => (string) $period->label,
                'kind_label' => $period->kind->label(),
            ],
            'moment' => [
                'label' => $momentLabel,
                'effective_at' => $effectiveAt->toDateString(),
            ],
            'author' => ['name' => (string) $author->name],
            'domains' => $domains,
            'students' => $students,
            'warnings' => $warnings,
        ];

        return EvaluationSheetExport::create([
            'class_id' => $class->id,
            'academic_period_id' => $period->id,
            'scope' => $scope,
            // Not «inovar»: nothing was exported anywhere. The adapter names
            // what produced the record, and here it is the act of keeping it.
            'adapter' => 'snapshot',
            'moment_label' => $momentLabel,
            'effective_at' => $effectiveAt->toDateString(),
            'payload' => $payload,
            'payload_hash' => CanonicalPayload::hash($payload),
            'warning_count' => count($warnings),
            'exported_with_warnings' => $warnings !== [],
            'exported_by' => $author->id,
            'exported_at' => now(),
        ]);
    }

    /**
     * The label to offer, which the teacher then confirms or replaces.
     *
     * Built from the period's OWN configuration — «Semestre — 1.º Semestre»,
     * «Período — 2.º Período» — so nothing anywhere hardcodes what a school
     * calls its own units of time (§6).
     *
     * A SUGGESTION AND NOTHING MORE. It arrives in an editable field and is
     * only saved once somebody presses the button.
     */
    public function suggestedLabel(AcademicPeriod $period): string
    {
        return $period->kind->label().' — '.$period->label;
    }

    /** What the teacher typed, tidied — never silently replaced. */
    public function labelFor(?string $given, AcademicPeriod $period): string
    {
        $given = $given === null ? '' : trim(preg_replace('/\s+/u', ' ', $given) ?? '');

        return $given !== '' ? $given : $this->suggestedLabel($period);
    }

    /**
     * The date has to be a date this period actually contains.
     *
     * READ FROM THE PERIOD'S OWN starts_on/ends_on, never inferred from its
     * name — the same rule CaptureInterimAssessment applies, for the same
     * reason (§6).
     */
    protected function guardTheDate(SchoolClass $class, AcademicPeriod $period, Carbon $effectiveAt): void
    {
        if ((int) $period->academic_year_id !== (int) $class->academic_year_id) {
            throw EvaluationSheetException::periodOutsideClass();
        }

        $date = $effectiveAt->copy()->startOfDay();

        if ($date->lessThan($period->starts_on->copy()->startOfDay())) {
            throw EvaluationSheetException::beforePeriodStart($period);
        }

        if ($date->greaterThan($period->ends_on->copy()->endOfDay())) {
            throw EvaluationSheetException::afterPeriodEnd($period);
        }

        // A photograph of a moment that has not arrived would be a photograph
        // of nothing — and would look identical to today's, which is worse.
        if ($date->greaterThan(now()->endOfDay())) {
            throw EvaluationSheetException::inTheFuture();
        }
    }

    /**
     * What was incomplete at the moment the photograph was taken, in words.
     *
     * SENTENCES, NOT RAW PAYLOAD. These are read months later by a teacher and
     * possibly by somebody who was not in the room; a dump of flags and ids
     * would be evidence of nothing. Every line is derived from what the read
     * model already states — no new judgement is made here, and in particular
     * a missing element is never reported as a zero (§13.3).
     *
     * @param  list<array<string, mixed>>  $domains
     * @param  list<array<string, mixed>>  $students
     * @return list<string>
     */
    protected function warnings(array $domains, array $students): array
    {
        $warnings = [];

        if ($domains === []) {
            $warnings[] = 'O perfil de avaliação desta turma não tem domínios definidos.';
        }

        if ($students === []) {
            $warnings[] = 'Não há alunos com resultados nesta pauta.';

            return $warnings;
        }

        foreach ($students as $student) {
            $name = (string) $student['name'];
            $overall = $student['overall'];
            $coverage = $student['coverage'];

            if (($coverage['no_elements'] ?? false) === true) {
                $warnings[] = "{$name}: sem elementos avaliados — a pauta não mostra qualquer resultado.";
            } elseif (($overall['has_coverage_warning'] ?? false) === true) {
                $warnings[] = "{$name}: cobertura parcial — nem todos os elementos previstos foram avaliados.";
            }

            $classification = $student['classification'];

            if ($classification === null) {
                $warnings[] = "{$name}: sem classificação registada.";

                continue;
            }

            // A proposal is not a decision (§3.3): a row that only carries the
            // system's suggestion is still a decision the teacher has not made.
            if ($classification['final_value'] === null && $classification['final_scale_level_id'] === null) {
                $warnings[] = "{$name}: classificação ainda não decidida pelo professor.";
            }
        }

        return $warnings;
    }
}
