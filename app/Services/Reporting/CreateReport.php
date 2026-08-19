<?php

namespace App\Services\Reporting;

use App\Domain\Reporting\SectionPlan;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\InterimAssessment;
use App\Models\Report;
use App\Models\ReportScopeKind;
use App\Models\ReportStatus;
use App\Models\ReportTemplate;
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
        ?ReportTemplate $template = null,
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
            template: $template,
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
        ?ReportTemplate $template = null,
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
            template: $template,
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
        ?ReportTemplate $template = null,
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
            template: $template,
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
     * The school-wide report (§24).
     *
     * ITS SCOPE IS A YEAR, or one period inside it. There is no «acumulado» at
     * school level: what is aggregated are classifications teachers assigned at
     * a moment, and an accumulated reading across classes on different scales
     * would be a figure with no meaning (§26).
     *
     * @param  list<string>|null  $sectionKeys
     * @param  array<string, mixed>  $options
     */
    public function forSchool(
        AcademicYear $year,
        User $author,
        ?AcademicPeriod $period = null,
        ?array $sectionKeys = null,
        ReportTone $tone = ReportTone::Objective,
        array $options = [],
        ?string $title = null,
        ?ReportTemplate $template = null,
    ): Report {
        $label = $period === null
            ? 'Ano letivo '.$year->label
            : (string) $period->label.' · '.$year->label;

        return $this->create(
            type: ReportType::School,
            author: $author,
            attributes: [
                'academic_year_id' => $year->id,
                'academic_period_id' => $period?->id,
                'scope_kind' => $period === null ? ReportScopeKind::Year : ReportScopeKind::Period,
                'scope_label' => $label,
                'starts_on' => $period?->starts_on,
                'ends_on' => $period?->ends_on,
            ],
            title: $title ?? ReportType::School->label().' · '.$label,
            tone: $tone,
            sectionKeys: $sectionKeys,
            // A school-wide report is aggregate by definition and never names a
            // student (§28). Not offered as a choice, and no template may turn
            // it on — this runs after the template's own options are merged.
            options: [...$options, 'name_students' => false],
            template: $template,
        );
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
        ?ReportTemplate $template = null,
    ): Report {
        if (! $this->capabilities->allowsType($type)) {
            throw new \RuntimeException('O plano desta organização não inclui este tipo de relatório.');
        }

        // THE TEMPLATE IS A STARTING POINT (§13). Its tone and options apply
        // only where the caller did not state one — a teacher who picked a tone
        // on the creation screen meant it.
        if ($template !== null) {
            $tone = $this->toneFrom($template, $tone, $sectionKeys === null);
            $options = array_replace($template->options(), $options);
        }

        // A tone the plan does not allow silently becomes the one it does,
        // rather than refusing the whole report over a presentation choice.
        // This runs AFTER the template, so a template cannot grant a register
        // the school is not entitled to (§28).
        if (! $this->capabilities->allowsTone($tone)) {
            $tone = ReportTone::Objective;
        }

        $plan = $this->planFor($type, $sectionKeys, $template);

        $report = DB::transaction(function () use ($type, $author, $attributes, $title, $tone, $plan, $options, $template): Report {
            $report = Report::create([
                'type' => $type,
                'status' => ReportStatus::Draft,
                'title' => $title,
                'tone' => $tone,
                'options' => $options === [] ? null : $options,
                'teacher_input_version' => Report::CURRENT_TEACHER_INPUT_VERSION,
                'created_by' => $author->id,
                'template_key' => $template?->key,
                // §15, §30: what the template said AT THIS MOMENT. Taken once
                // and never read back, so editing the template afterwards
                // changes nothing here (§14).
                'template_snapshot' => $template?->snapshot(),
                ...$attributes,
            ]);

            $this->buildSections($report, $plan);

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
     * WHICH SECTIONS, IN WHAT ORDER — the three sources, in precedence order.
     *
     * An explicit list from the creation form wins: the teacher just ticked
     * those boxes. A template comes next, bringing its own order with it. The
     * catalogue's defaults are the floor.
     *
     * @param  list<string>|null  $chosen
     */
    protected function planFor(ReportType $type, ?array $chosen, ?ReportTemplate $template): SectionPlan
    {
        if ($template !== null) {
            $plan = SectionPlan::fromTemplate($type, $this->capabilities, $template->sections());

            // The template arranges; an explicit checklist decides what prints.
            // Letting either one win outright would throw the other away.
            return $chosen === null ? $plan : $plan->withInclusion($chosen);
        }

        if ($chosen !== null) {
            return SectionPlan::fromChosenKeys($type, $this->capabilities, $chosen);
        }

        return SectionPlan::defaultFor($type, $this->capabilities);
    }

    /**
     * A template's tone applies only where the caller did not state one.
     *
     * `$callerWasSilent` is true when no explicit section list was posted,
     * which is the same signal: the creation form sends both together, so a
     * form that named sections also named a tone.
     */
    protected function toneFrom(ReportTemplate $template, ReportTone $tone, bool $callerWasSilent): ReportTone
    {
        return $callerWasSilent ? ($template->tone() ?? $tone) : $tone;
    }

    protected function buildSections(Report $report, SectionPlan $plan): void
    {
        $headings = [];

        foreach ($this->capabilities->sectionsFor($report->type) as $definition) {
            $headings[$definition->key->value] = $definition->heading;
        }

        $position = 0;

        foreach ($plan->entries as $entry) {
            $position += 10;

            $report->sections()->create([
                'key' => $entry['key']->value,
                'heading' => $headings[$entry['key']->value] ?? $entry['key']->value,
                'position' => $position,
                // Every allowed section gets a row; `included` is what decides
                // whether it prints. Keeping the row means a teacher can turn a
                // section back on without losing what was in it (§45, §36).
                'included' => $entry['included'],
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
