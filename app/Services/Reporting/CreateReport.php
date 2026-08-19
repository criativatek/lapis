<?php

namespace App\Services\Reporting;

use App\Domain\Reporting\SectionDefinition;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\InterimAssessment;
use App\Models\Report;
use App\Models\ReportScopeKind;
use App\Models\ReportStatus;
use App\Models\ReportTone;
use App\Models\ReportType;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Creating a draft: the envelope, its sections, and a first generation.
 *
 * THE SCOPE IS RESOLVED HERE AND WRITTEN DOWN (§29). Every report carries an
 * explicit stretch of time in words, decided once at creation, so that no
 * sentence downstream has to reconstruct a period name and no two sections can
 * disagree about which moment they describe.
 *
 * WHICH FIGURE ANSWERS IS NOT ASKED OF THE TEACHER. They choose a period; the
 * profile version decides whether the reading that answers for it is the
 * period's own weighted average or the accumulated one, exactly as every other
 * screen does (PrimaryResultScope). Offering that as a choice here would let a
 * report contradict Resultados on the same class (§7).
 *
 * WHAT THE PLAN DOES NOT ALLOW IS NOT CREATED. Section keys arriving from a
 * form are filtered against ReportCapabilities before a row exists, so a Base
 * organization cannot end up with an empty «Propostas de superação» that looks
 * like a bug rather than a plan boundary (§4).
 */
class CreateReport
{
    public function __construct(
        protected ReportCapabilities $capabilities,
        protected ComposeReport $composer,
        protected AuditLog $audit,
    ) {}

    /**
     * @param  list<string>|null  $sectionKeys  Null keeps the defaults for the type.
     * @param  array<string, mixed>  $options
     */
    public function forClass(
        SchoolClass $class,
        User $author,
        ?AcademicPeriod $period = null,
        ?InterimAssessment $interim = null,
        ?array $sectionKeys = null,
        ReportTone $tone = ReportTone::Objective,
        array $options = [],
        ?string $title = null,
    ): Report {
        return $this->create(
            type: ReportType::SchoolClass,
            author: $author,
            attributes: [
                'class_id' => $class->id,
                'academic_year_id' => $class->academic_year_id,
                'academic_period_id' => $period?->id,
                'interim_assessment_id' => $interim?->id,
                ...$this->scopeFor($period, $interim),
            ],
            title: $title ?? $this->defaultTitle(ReportType::SchoolClass, $class->label, $period, $interim),
            tone: $tone,
            sectionKeys: $sectionKeys,
            options: $options,
        );
    }

    /**
     * @param  list<string>|null  $sectionKeys
     * @param  array<string, mixed>  $options
     */
    public function forStudent(
        Enrollment $enrollment,
        User $author,
        ?AcademicPeriod $period = null,
        ?array $sectionKeys = null,
        ReportTone $tone = ReportTone::Objective,
        array $options = [],
        ?string $title = null,
    ): Report {
        $class = $enrollment->schoolClass;

        return $this->create(
            type: ReportType::Student,
            author: $author,
            attributes: [
                'class_id' => $class->id,
                'enrollment_id' => $enrollment->id,
                'academic_year_id' => $class->academic_year_id,
                'academic_period_id' => $period?->id,
                ...$this->scopeFor($period, null),
            ],
            title: $title ?? $this->defaultTitle(
                ReportType::Student,
                optional($enrollment->student->identity)->display_name ?? $class->label,
                $period,
                null,
            ),
            tone: $tone,
            sectionKeys: $sectionKeys,
            options: $options,
        );
    }

    /**
     * A Registos report (§21).
     *
     * ITS SCOPE IS DATES, NOT A PERIOD. A logbook question is «o que aconteceu
     * entre estas duas datas», and the report says so in words rather than
     * borrowing a period name that only approximates the interval. Where the
     * teacher picked a period instead, the period's own dates become the
     * interval and the label is the period's — one scope, stated once.
     *
     * @param  list<string>|null  $sectionKeys
     * @param  array<string, mixed>  $options
     */
    public function forRecords(
        AcademicYear $year,
        User $author,
        ?SchoolClass $class = null,
        ?Enrollment $enrollment = null,
        ?AcademicPeriod $period = null,
        ?CarbonInterface $startsOn = null,
        ?CarbonInterface $endsOn = null,
        ?array $sectionKeys = null,
        ReportTone $tone = ReportTone::Objective,
        array $options = [],
        ?string $title = null,
    ): Report {
        $scope = $this->recordsScope($year, $period, $startsOn, $endsOn);

        return $this->create(
            type: ReportType::Records,
            author: $author,
            attributes: [
                'class_id' => $class?->id,
                'enrollment_id' => $enrollment?->id,
                'academic_year_id' => $year->id,
                'academic_period_id' => $period?->id,
                ...$scope,
            ],
            title: $title ?? ReportType::Records->label().' · '
                .($class === null ? 'Todas as turmas' : $class->label).' · '.$scope['scope_label'],
            tone: $tone,
            sectionKeys: $sectionKeys,
            options: $options,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function recordsScope(
        AcademicYear $year,
        ?AcademicPeriod $period,
        ?CarbonInterface $startsOn,
        ?CarbonInterface $endsOn,
    ): array {
        if ($period !== null) {
            return [
                'scope_kind' => ReportScopeKind::Period,
                'scope_label' => (string) $period->label,
                'starts_on' => $period->starts_on,
                'ends_on' => $period->ends_on,
            ];
        }

        if ($startsOn !== null || $endsOn !== null) {
            return [
                'scope_kind' => ReportScopeKind::DateRange,
                'scope_label' => $this->rangeLabel($startsOn, $endsOn),
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
            ];
        }

        return [
            'scope_kind' => ReportScopeKind::Year,
            'scope_label' => 'Ano letivo '.$year->label,
            'starts_on' => null,
            'ends_on' => null,
        ];
    }

    protected function rangeLabel(?CarbonInterface $startsOn, ?CarbonInterface $endsOn): string
    {
        return match (true) {
            $startsOn !== null && $endsOn !== null => 'De '.$startsOn->format('d/m/Y').' a '.$endsOn->format('d/m/Y'),
            $startsOn !== null => 'A partir de '.$startsOn->format('d/m/Y'),
            default => 'Até '.$endsOn?->format('d/m/Y'),
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>|null  $sectionKeys
     * @param  array<string, mixed>  $options
     */
    public function create(
        ReportType $type,
        User $author,
        array $attributes,
        string $title,
        ReportTone $tone = ReportTone::Objective,
        ?array $sectionKeys = null,
        array $options = [],
    ): Report {
        if (! $this->capabilities->allowsType($type)) {
            throw new \RuntimeException('O plano desta organização não inclui este tipo de relatório.');
        }

        // A tone the plan does not allow silently becomes the one it does,
        // rather than refusing the whole report over a presentation choice.
        if (! $this->capabilities->allowsTone($tone)) {
            $tone = ReportTone::Objective;
        }

        $report = DB::transaction(function () use ($type, $author, $attributes, $title, $tone, $sectionKeys, $options): Report {
            $report = Report::create([
                'type' => $type,
                'status' => ReportStatus::Draft,
                'title' => $title,
                'tone' => $tone,
                'options' => $options === [] ? null : $options,
                'teacher_input_version' => Report::CURRENT_TEACHER_INPUT_VERSION,
                'created_by' => $author->id,
                ...$attributes,
            ]);

            $this->buildSections($report, $sectionKeys);

            return $report;
        });

        $this->composer->generate($report);

        $this->audit->record(
            'report.created',
            $report,
            $author,
            summary: "Relatório «{$report->title}» criado como rascunho.",
            properties: ['type' => $type->value, 'scope' => $report->scope_label],
        );

        return $report->fresh(['sections']) ?? $report;
    }

    /**
     * @param  list<string>|null  $chosen
     */
    protected function buildSections(Report $report, ?array $chosen): void
    {
        $available = $this->capabilities->sectionsFor($report->type);

        // Null means «the defaults»; an explicit list is honoured but still
        // filtered — a key the plan does not allow never becomes a row.
        $wanted = $chosen === null
            ? array_map(fn (SectionDefinition $definition) => $definition->key->value,
                array_filter($available, fn (SectionDefinition $definition) => $definition->defaultIncluded))
            : $chosen;

        $position = 0;

        foreach ($available as $definition) {
            $position += 10;

            $report->sections()->create([
                'key' => $definition->key->value,
                'heading' => $definition->heading,
                'position' => $position,
                // Every allowed section gets a row; `included` is what decides
                // whether it prints. Keeping the row means a teacher can turn a
                // section back on without losing what was in it (§45).
                'included' => in_array($definition->key->value, $wanted, strict: true),
            ]);
        }
    }

    /**
     * The temporal scope, decided once (§29).
     *
     * @return array<string, mixed>
     */
    protected function scopeFor(?AcademicPeriod $period, ?InterimAssessment $interim): array
    {
        if ($interim !== null) {
            return [
                'scope_kind' => ReportScopeKind::Interim,
                'scope_label' => $interim->name.' ('.$interim->reference_date->format('d/m/Y').')',
                'starts_on' => null,
                'ends_on' => $interim->reference_date,
            ];
        }

        if ($period !== null) {
            return [
                'scope_kind' => ReportScopeKind::Period,
                'scope_label' => (string) $period->label,
                'starts_on' => $period->starts_on,
                'ends_on' => $period->ends_on,
            ];
        }

        // No period chosen: the report covers the year so far, and says so
        // rather than leaving the reader to guess from an unlabelled figure.
        return [
            'scope_kind' => ReportScopeKind::Year,
            'scope_label' => 'Ano letivo até ao momento',
            'starts_on' => null,
            'ends_on' => null,
        ];
    }

    protected function defaultTitle(
        ReportType $type,
        string $subject,
        ?AcademicPeriod $period,
        ?InterimAssessment $interim,
    ): string {
        $when = match (true) {
            $interim !== null => $interim->name,
            $period !== null => (string) $period->label,
            default => 'Ano letivo',
        };

        return $type->label().' · '.$subject.' · '.$when;
    }
}
