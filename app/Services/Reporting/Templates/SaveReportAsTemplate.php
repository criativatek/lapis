<?php

namespace App\Services\Reporting\Templates;

use App\Domain\Reporting\SectionPlan;
use App\Models\Report;
use App\Models\ReportSection;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateKind;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Reporting\ReportCapabilities;
use Illuminate\Support\Facades\DB;

/**
 * «Guardar estrutura como modelo» (§18, §37).
 *
 * THE DANGEROUS DIRECTION. Everywhere else in this module the risk is a report
 * saying something the data does not support. Here it is the opposite: a report
 * is FULL of one class's real figures, one child's name, one teacher's
 * paragraph about a real difficulty — and a template made from it is reused
 * next term, on another class, possibly by a colleague.
 *
 * So this is an ALLOW-LIST, not a copy with deletions. Nothing is carried over
 * unless it is named below, which means a field added to Report later cannot
 * leak by being forgotten:
 *
 *   carried    which sections exist, whether each prints, the order the teacher
 *              put them in, the tone, and the two structural options
 *              (`name_students`, `detailed`, and the Registos kind filter,
 *              which is a filter and not data).
 *
 *   refused    every section body, generated or edited. The teacher's
 *              characterisation. Validated difficulties and the strategies
 *              chosen for them. Flagged students. The planning statement. The
 *              closing note. Every figure, every name, every date, the class,
 *              the period, the enrolment.
 *
 * The refused list is long because it is the whole of `teacher_input` and the
 * whole of `report_sections.body` — a template holds arrangement, and judgement
 * about a real class is not arrangement.
 */
class SaveReportAsTemplate
{
    /**
     * Options that describe HOW the document is built rather than WHAT happened
     * in it. Everything else in `options` stays behind.
     */
    protected const STRUCTURAL_OPTIONS = ['name_students', 'detailed', 'kinds'];

    public function __construct(
        protected ReportCapabilities $capabilities,
        protected AuditLog $audit,
    ) {}

    public function save(
        Report $report,
        User $author,
        ReportTemplateKind $kind,
        string $name,
        ?string $description = null,
        bool $isDefault = false,
    ): ReportTemplate {
        if ($kind->isSystem()) {
            throw new \RuntimeException('Um modelo do sistema não pode ser criado a partir de um relatório.');
        }

        $template = DB::transaction(function () use ($report, $author, $kind, $name, $description, $isDefault): ReportTemplate {
            $template = ReportTemplate::create([
                'kind' => $kind,
                // A person's template is identified by its ulid and named by
                // them; `key` belongs to the seeder's rows.
                'key' => null,
                // Set for both kinds: on a personal template it is the owner,
                // on an institutional one it records who created it.
                'user_id' => $author->getKey(),
                'report_type' => $report->type,
                'name' => $name,
                'description' => $description,
                'settings' => $this->settingsFrom($report),
                'is_default' => false,
                'is_active' => true,
            ]);

            if ($isDefault) {
                $this->makeDefault($template);
            }

            return $template;
        });

        $this->audit->record(
            'report_template.created',
            $template,
            $author,
            summary: "Modelo «{$template->name}» guardado a partir de «{$report->title}».",
            properties: ['kind' => $kind->value, 'report_type' => $report->type->value],
        );

        return $template->fresh() ?? $template;
    }

    /**
     * The allow-list, applied.
     *
     * @return array<string, mixed>
     */
    public function settingsFrom(Report $report): array
    {
        $sections = array_values($report->sections()
            ->orderBy('position')
            ->get()
            ->map(fn (ReportSection $section) => ['key' => $section->key, 'included' => $section->included])
            ->all());

        $plan = SectionPlan::fromSections($report->type, $this->capabilities, $sections);

        return [
            'sections' => $plan->toArray(),
            'tone' => $report->tone->value,
            'options' => $this->structuralOptions($report),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function structuralOptions(Report $report): array
    {
        $options = $report->options ?? [];
        $kept = [];

        foreach (self::STRUCTURAL_OPTIONS as $key) {
            if (array_key_exists($key, $options)) {
                $kept[$key] = $options[$key];
            }
        }

        return $kept;
    }

    /**
     * One preferred template per owner per report type (§44).
     *
     * The previous preference is cleared in the same transaction, so two
     * contradictory defaults cannot exist even if two tabs save at once. Scope
     * is the owner: a teacher's preference is theirs, a school's is the
     * school's.
     */
    public function makeDefault(ReportTemplate $template): void
    {
        DB::transaction(function () use ($template): void {
            $siblings = ReportTemplate::query()
                ->where('kind', $template->kind)
                ->where('report_type', $template->report_type)
                ->whereKeyNot($template->getKey());

            if ($template->kind === ReportTemplateKind::Personal) {
                $siblings->where('user_id', $template->user_id);
            }

            $siblings->update(['is_default' => false]);

            $template->forceFill(['is_default' => true])->save();
        });
    }
}
