<?php

namespace App\Services\Reporting\Templates;

use App\Models\ReportTemplate;
use App\Models\ReportTemplateKind;
use App\Models\ReportType;
use App\Models\User;
use App\Services\Reporting\ReportCapabilities;
use Illuminate\Support\Collection;

/**
 * Which templates a teacher may pick from, and which one is pre-selected.
 *
 * ONE PLACE, so the picker on the creation screen, the listing in the
 * management area and the server-side check when a template id is posted all
 * answer the same question. A picker that offers something the server then
 * refuses is a bug report waiting to happen.
 *
 * ONLY ACTIVE ONES ARE OFFERED (§43). A deactivated template stays visible in
 * the management area and stays resolvable by the reports that were built from
 * it — it simply stops being a choice for new ones.
 *
 * THE PLAN FILTERS THE LIST, not the template's contents. A template for a
 * report type the school is not entitled to is not offered at all; a template
 * whose sections exceed the plan is offered, and SectionPlan drops what it may
 * not include (§23).
 */
class TemplateResolver
{
    public function __construct(protected ReportCapabilities $capabilities) {}

    /**
     * @return Collection<int, ReportTemplate>
     */
    public function availableTo(User $user, ReportType $type): Collection
    {
        if (! $this->capabilities->allowsType($type)) {
            return collect();
        }

        return ReportTemplate::query()
            ->visibleTo($user)
            ->active()
            ->where('report_type', $type)
            // The school's own standard first, then the teacher's own, then the
            // product's — most specific first, which is the order a teacher
            // would look in.
            ->orderByRaw("CASE kind WHEN 'institutional' THEN 0 WHEN 'personal' THEN 1 ELSE 2 END")
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    /**
     * The one to pre-select (§17).
     *
     * A teacher who has marked a preference gets it. Otherwise the first of the
     * ordered list, which is the school's standard where one exists and the
     * product's otherwise — so the common case is a screen they can submit
     * without choosing anything.
     */
    public function preferredFor(User $user, ReportType $type): ?ReportTemplate
    {
        $available = $this->availableTo($user, $type);

        return $available->first(fn (ReportTemplate $template) => $template->is_default
            && $template->kind === ReportTemplateKind::Personal)
            ?? $available->first();
    }

    /**
     * A template chosen by ulid, or null — refusing anything this user may not
     * see, may not use, or that belongs to another report type.
     *
     * The global scope has already refused another organization's rows; this
     * closes the two gaps it cannot see: another teacher's personal template,
     * and a template for a different type.
     */
    public function resolve(User $user, ReportType $type, ?string $ulid): ?ReportTemplate
    {
        if ($ulid === null || $ulid === '') {
            return null;
        }

        return $this->availableTo($user, $type)
            ->first(fn (ReportTemplate $template) => $template->ulid === $ulid);
    }

    /**
     * The picker's payload.
     *
     * @return list<array<string, mixed>>
     */
    public function optionsFor(User $user, ReportType $type): array
    {
        return array_values($this->availableTo($user, $type)
            ->map(fn (ReportTemplate $template) => [
                'ulid' => $template->ulid,
                'name' => $template->name,
                'description' => $template->description,
                'kind' => $template->kind->value,
                'kind_label' => $template->kind->label(),
                'is_default' => $template->is_default,
                // Which sections this template turns on, so the creation
                // screen's checklist can follow it instead of contradicting it.
                'included' => array_values(array_map(
                    fn (array $section): string => (string) $section['key'],
                    array_filter(
                        $template->sections(),
                        fn (array $section): bool => ($section['included'] ?? false) === true,
                    ),
                )),
            ])
            ->all());
    }
}
