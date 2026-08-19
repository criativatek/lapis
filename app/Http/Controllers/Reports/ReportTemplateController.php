<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reporting\SectionCatalogue;
use App\Domain\Reporting\SectionPlan;
use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateKind;
use App\Models\ReportTone;
use App\Models\ReportType;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Reporting\ReportCapabilities;
use App\Services\Reporting\Templates\SaveReportAsTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Relatórios → Modelos (§25).
 *
 * Inside the module rather than under Definições, because a template is part of
 * how reports are produced and a teacher looks for it where they make them.
 *
 * EVERY AUTHORITY QUESTION GOES THROUGH THE POLICY (§39). There is no owner id
 * compared anywhere in this file.
 */
class ReportTemplateController extends Controller
{
    public function __construct(
        protected ReportCapabilities $capabilities,
        protected SaveReportAsTemplate $saver,
        protected AuditLog $audit,
    ) {}

    public function index(): Response
    {
        Gate::authorize('viewAny', ReportTemplate::class);

        $user = $this->user();

        $templates = ReportTemplate::query()
            ->visibleTo($user)
            ->with('author')
            ->orderBy('report_type')
            ->orderByRaw("CASE kind WHEN 'institutional' THEN 0 WHEN 'personal' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->get()
            ->filter(fn (ReportTemplate $template) => Gate::allows('view', $template))
            ->map(fn (ReportTemplate $template) => [
                'ulid' => $template->ulid,
                'name' => $template->name,
                'description' => $template->description,
                'kind' => $template->kind->value,
                'kind_label' => $template->kind->label(),
                'report_type' => $template->report_type->value,
                'report_type_label' => $template->report_type->label(),
                'author' => $template->author?->name,
                'is_default' => $template->is_default,
                'is_active' => $template->is_active,
                'updated_at' => $template->updated_at->toIso8601String(),
                'sections' => count($template->sections()),
                'can' => [
                    'update' => Gate::allows('update', $template),
                    'duplicate' => Gate::allows('duplicate', $template),
                ],
            ])
            ->values()
            ->all();

        return Inertia::render('reports/templates/Index', [
            'templates' => $templates,
            'canCreate' => [
                'personal' => Gate::allows('createKind', [ReportTemplate::class, ReportTemplateKind::Personal]),
                'institutional' => Gate::allows('createKind', [ReportTemplate::class, ReportTemplateKind::Institutional]),
            ],
            'reportTypes' => array_map(
                fn (ReportType $type) => ['value' => $type->value, 'label' => $type->label()],
                $this->capabilities->availableTypes(),
            ),
        ]);
    }

    /** The editor: name, description, tone, sections and their order (§27). */
    public function edit(ReportTemplate $template): Response
    {
        Gate::authorize('view', $template);

        return Inertia::render('reports/templates/Edit', [
            'template' => [
                'ulid' => $template->ulid,
                'name' => $template->name,
                'description' => $template->description,
                'kind' => $template->kind->value,
                'kind_label' => $template->kind->label(),
                'report_type' => $template->report_type->value,
                'report_type_label' => $template->report_type->label(),
                'tone' => $template->tone()->value ?? ReportTone::Objective->value,
                'options' => $template->options(),
                'is_default' => $template->is_default,
                'is_active' => $template->is_active,
            ],
            // The plan as it stands, already reconciled with the catalogue and
            // this school's capabilities — so the editor shows what the
            // template would actually produce today.
            'sections' => $this->sectionRows($template),
            'tones' => array_map(
                fn (ReportTone $tone) => [
                    'value' => $tone->value,
                    'label' => $tone->label(),
                    'description' => $tone->description(),
                ],
                $this->capabilities->availableTones(),
            ),
            'can' => ['update' => Gate::allows('update', $template)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::enum(ReportTemplateKind::class)],
            'report_type' => ['required', Rule::enum(ReportType::class)],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $kind = ReportTemplateKind::from($data['kind']);
        $type = ReportType::from($data['report_type']);

        Gate::authorize('createKind', [ReportTemplate::class, $kind]);

        abort_unless($this->capabilities->allowsType($type), 403);

        $template = ReportTemplate::create([
            'kind' => $kind,
            'key' => null,
            'user_id' => $this->user()->getKey(),
            'report_type' => $type,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            // A new template starts as the catalogue's own arrangement; the
            // teacher changes it in the editor.
            'settings' => [
                'sections' => SectionPlan::defaultFor($type, $this->capabilities)->toArray(),
                'tone' => ReportTone::Objective->value,
                'options' => ['name_students' => false],
            ],
            'is_default' => false,
            'is_active' => true,
        ]);

        $this->audit->record(
            'report_template.created',
            $template,
            $this->user(),
            summary: "Modelo «{$template->name}» criado.",
            properties: ['kind' => $kind->value, 'report_type' => $type->value],
        );

        return redirect()->route('reports.templates.edit', $template);
    }

    public function update(Request $request, ReportTemplate $template): RedirectResponse
    {
        Gate::authorize('update', $template);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'tone' => ['sometimes', Rule::enum(ReportTone::class)],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'sections' => ['sometimes', 'array'],
            'sections.*.key' => ['required', 'string', 'max:64'],
            'sections.*.included' => ['required', 'boolean'],
            'name_students' => ['sometimes', 'boolean'],
        ]);

        $attributes = [];

        if (array_key_exists('name', $data)) {
            $attributes['name'] = $data['name'];
        }

        if (array_key_exists('description', $data)) {
            $attributes['description'] = $data['description'];
        }

        if (array_key_exists('is_active', $data)) {
            Gate::authorize('deactivate', $template);
            $attributes['is_active'] = (bool) $data['is_active'];
        }

        $settings = $template->settings;

        if (array_key_exists('tone', $data)) {
            $tone = ReportTone::from($data['tone']);

            // §28: a template may not turn on a register the plan does not
            // include. Refused here rather than silently stored and dropped
            // later, so the editor's own state stays truthful.
            if ($this->capabilities->allowsTone($tone)) {
                $settings['tone'] = $tone->value;
            }
        }

        if (array_key_exists('sections', $data)) {
            // Ordered as posted, then reconciled: a key this type or this plan
            // does not have never reaches the stored settings (§23).
            $settings['sections'] = SectionPlan::fromSections(
                $template->report_type,
                $this->capabilities,
                array_map(
                    fn (array $section): array => ['key' => $section['key'], 'included' => (bool) $section['included']],
                    array_values($data['sections']),
                ),
            )->toArray();
        }

        if (array_key_exists('name_students', $data)) {
            $settings['options'] = array_replace(
                is_array($settings['options'] ?? null) ? $settings['options'] : [],
                ['name_students' => (bool) $data['name_students']],
            );
        }

        $attributes['settings'] = $settings;

        $template->update($attributes);

        if (($data['is_default'] ?? false) === true) {
            $this->saver->makeDefault($template->fresh() ?? $template);
        }

        return back();
    }

    /**
     * Duplicating (§42) — always into a PERSONAL template owned by whoever
     * pressed the button. Copying ownership would hand somebody a template they
     * can then edit on the original owner's behalf.
     */
    public function duplicate(ReportTemplate $template): RedirectResponse
    {
        Gate::authorize('duplicate', $template);

        $copy = ReportTemplate::create([
            'kind' => ReportTemplateKind::Personal,
            'key' => null,
            'user_id' => $this->user()->getKey(),
            'report_type' => $template->report_type,
            'name' => 'Cópia de '.$template->name,
            'description' => $template->description,
            'settings' => $template->settings,
            'is_default' => false,
            'is_active' => true,
        ]);

        $this->audit->record(
            'report_template.duplicated',
            $copy,
            $this->user(),
            summary: "Modelo «{$copy->name}» duplicado a partir de «{$template->name}».",
        );

        return redirect()->route('reports.templates.edit', $copy);
    }

    /**
     * Saving a draft's arrangement as a template (§18).
     *
     * The report is not changed in any way — what comes out is a new template
     * carrying only structure.
     */
    public function storeFromReport(Request $request, Report $report): RedirectResponse
    {
        Gate::authorize('view', $report);

        $data = $request->validate([
            'kind' => ['required', Rule::enum(ReportTemplateKind::class)],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $kind = ReportTemplateKind::from($data['kind']);

        Gate::authorize('createKind', [ReportTemplate::class, $kind]);

        $template = $this->saver->save(
            report: $report,
            author: $this->user(),
            kind: $kind,
            name: $data['name'],
            description: $data['description'] ?? null,
            isDefault: (bool) ($data['is_default'] ?? false),
        );

        return redirect()->route('reports.templates.edit', $template);
    }

    /**
     * The rows the editor shows: every section this type and this plan allow,
     * in the template's own order, each saying whether it prints.
     *
     * @return list<array<string, mixed>>
     */
    protected function sectionRows(ReportTemplate $template): array
    {
        $plan = SectionPlan::fromTemplate(
            $template->report_type,
            $this->capabilities,
            $template->sections(),
        );

        $definitions = [];

        foreach (SectionCatalogue::for($template->report_type) as $definition) {
            $definitions[$definition->key->value] = $definition;
        }

        return array_map(function (array $entry) use ($definitions): array {
            $definition = $definitions[$entry['key']->value] ?? null;

            return [
                'key' => $entry['key']->value,
                'heading' => $definition->heading ?? $entry['key']->value,
                'included' => $entry['included'],
                'needs_teacher_input' => $definition->needsTeacherInput ?? false,
                'may_name_students' => $definition->mayNameStudents ?? false,
            ];
        }, $plan->entries);
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
