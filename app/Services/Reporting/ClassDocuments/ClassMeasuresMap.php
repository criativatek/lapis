<?php

namespace App\Services\Reporting\ClassDocuments;

use App\Models\Enrollment;
use App\Models\Intervention;
use App\Models\InterventionTargetType;
use App\Models\InterventionType;
use App\Models\SchoolClass;
use App\Models\SupportMeasureCode;
use App\Models\SupportMeasureLevel;
use App\Services\Documents\DocumentIdentity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * The Mapa de Medidas Educativas of one class, as a neutral structure.
 *
 * A DOCUMENT MADE ON THE SPOT, NOT A REPORT. Nothing here is a `Report`, nothing
 * is written, and nothing is snapshotted: it reads Estratégias e Medidas as it
 * stands, and the same structure feeds the on-screen preview and the PDF, so the
 * two can only differ in how they draw it.
 *
 * WHO APPEARS. The students ACTIVE in the class today who have at least one
 * measure in a column being shown. A student who left is not on a document about
 * the class as it stands, and a measure whose participants have all left reaches
 * nobody and is neither printed nor counted.
 *
 * WHAT IS PRINTED, AND WHAT NEVER IS. Designation, legal framing, the real state
 * and whether it was for a group — and nothing else. No description, reason,
 * objective, free-text strategy, follow-up note, effectiveness, date, domain,
 * author or identifier of any kind: this sheet may be handed to a person who is
 * not the teacher, and the teacher's working notes are not for it.
 *
 * `available_for_reports` IS THE TEACHER'S DECISION and is respected exactly as
 * ClassReportSource does: a measure marked as not available for reports does not
 * appear. What the document does say, in a footnote, is HOW MANY were left out —
 * a map that silently dropped some would read as complete.
 *
 * A CLASS-WIDE MEASURE IS PRINTED ONCE, in its own block. It is decided by
 * `target_type`, never by an empty `enrollment_id`: groups have no
 * `enrollment_id` either, and reading «NULL» as «the whole class» would print a
 * three-student group as if it were everybody. A class-wide measure does not
 * make any student «com medidas» — it names nobody.
 *
 * NAMES ARE DECRYPTED ONLY FOR WHOEVER APPEARS. The identity is loaded for the
 * students that have items, so a name that is not going to be printed is never
 * decrypted at all.
 *
 * NO AI, NO NETWORK. Everything here is a query and some arithmetic.
 */
class ClassMeasuresMap
{
    public function __construct(protected DocumentIdentity $identity) {}

    /**
     * @return array<string, mixed>
     */
    public function build(SchoolClass $class, bool $showImplemented, bool $showPlanned): array
    {
        $class->loadMissing(['subject', 'academicYear', 'organization.identity']);

        $shown = array_values(array_filter([
            $showImplemented ? MeasureMapColumn::Implemented : null,
            $showPlanned ? MeasureMapColumn::Planned : null,
        ]));

        $roster = $class->activeEnrollments()
            ->inClassOrder()
            ->get();

        $rosterIds = array_values($roster->pluck('id')->map(fn ($id): int => (int) $id)->all());

        $classWide = ['implemented' => [], 'planned' => []];
        $perEnrollment = [];
        $distinct = [
            MeasureMapColumn::Implemented->value => [],
            MeasureMapColumn::Planned->value => [],
        ];

        foreach ($this->interventions($class, $shown) as $intervention) {
            $column = MeasureMapColumn::forStatus($intervention->status);

            // The query only fetches states that have a column; this guard is
            // for the type checker and for a state added later.
            if ($column === null) {
                continue;
            }

            if ($intervention->target_type === InterventionTargetType::SchoolClass) {
                $classWide[$column->value][] = $this->item($intervention, $column);
                $distinct[$column->value][$intervention->id] = true;

                continue;
            }

            $reached = array_intersect($this->participantIds($intervention), $rosterIds);

            foreach ($reached as $enrollmentId) {
                $perEnrollment[$enrollmentId][$column->value][] = $this->item($intervention, $column);
            }

            if ($reached !== []) {
                $distinct[$column->value][$intervention->id] = true;
            }
        }

        // Only now, and only for them: decrypting a name is the sensitive step.
        $withMeasures = new Collection(
            $roster->filter(fn (Enrollment $enrollment): bool => isset($perEnrollment[$enrollment->id]))->values()->all(),
        );
        $withMeasures->load('student.identity');

        $students = $withMeasures->map(fn (Enrollment $enrollment): array => [
            'number' => $enrollment->class_number,
            'name' => $enrollment->student->identity->display_name ?? __('(sem identidade)'),
            'implemented' => $this->sorted($perEnrollment[$enrollment->id][MeasureMapColumn::Implemented->value] ?? []),
            'planned' => $this->sorted($perEnrollment[$enrollment->id][MeasureMapColumn::Planned->value] ?? []),
        ])->values()->all();

        $classWide = [
            'implemented' => $this->sorted($classWide['implemented']),
            'planned' => $this->sorted($classWide['planned']),
        ];

        $summary = [
            'students' => $roster->count(),
            'students_with_measures' => count($students),
            'implemented' => $showImplemented ? count($distinct[MeasureMapColumn::Implemented->value]) : null,
            'planned' => $showPlanned ? count($distinct[MeasureMapColumn::Planned->value]) : null,
        ];

        $issuedAt = now();

        return [
            'title' => __('Mapa de Medidas Educativas'),
            'subtitle' => $class->label.' · '.$class->subject->name.' · '.__('Ano letivo').' '.$class->academicYear->label,
            'identity' => $this->identityPayload($class),
            'issued_at' => $issuedAt->toIso8601String(),
            'issued_label' => __('Emitido a :moment', [
                'moment' => $issuedAt->copy()->timezone((string) config('app.timezone'))->format('d/m/Y, H:i'),
            ]),
            'columns' => ['implemented' => $showImplemented, 'planned' => $showPlanned],
            'column_labels' => [
                MeasureMapColumn::Implemented->value => MeasureMapColumn::Implemented->label(),
                MeasureMapColumn::Planned->value => MeasureMapColumn::Planned->label(),
            ],
            'summary' => $summary,
            'summary_line' => $this->summaryLine($summary),
            'class_wide' => $classWide,
            'students' => $students,
            'excluded_note' => $this->excludedNote($this->excludedCount($class, $shown, $rosterIds)),
            'criteria' => __('Implementadas: medidas em curso, concluídas ou suspensas. A implementar: medidas planeadas. Não inclui medidas canceladas.'),
            'empty_message' => __('Não existem medidas educativas registadas para os alunos desta turma no contexto selecionado.'),
            // Printing now is not finalizing (ADR-0005 §4): the sheet says so
            // itself, on the screen and on paper alike.
            'notice' => __('Documento gerado a partir dos registos à data de emissão; não é um relatório finalizado.'),
            'is_empty' => $students === []
                && $classWide['implemented'] === []
                && $classWide['planned'] === [],
        ];
    }

    /**
     * The interventions that can appear, with just what is needed to place and
     * name them — participants as bare ids, never their content.
     *
     * @param  list<MeasureMapColumn>  $shown
     * @return Collection<int, Intervention>
     */
    protected function interventions(SchoolClass $class, array $shown): Collection
    {
        $statuses = $this->statusValues($shown);

        if ($statuses === []) {
            return new Collection;
        }

        return Intervention::query()
            ->forClass($class->id)
            ->where('available_for_reports', true)
            ->whereIn('status', $statuses)
            ->with(['participants:enrollments.id', 'supportMeasures'])
            ->get();
    }

    /**
     * How many measures of this class the teacher kept out of reports and that
     * would otherwise have been on THIS map: a column being shown, and someone
     * on today's roll to put them under — the same reach `build()` gives the
     * measures it does print (the whole class, an active participant, or the
     * legacy single-student column when the pivot is empty). A measure kept out
     * whose students have all left would not have appeared anyway, and counting
     * it would make the footnote claim an omission that is not one.
     *
     * One COUNT, so nothing of their content is ever loaded, and a constant
     * number of queries whatever the class holds.
     *
     * @param  list<MeasureMapColumn>  $shown
     * @param  list<int>  $rosterIds
     */
    protected function excludedCount(SchoolClass $class, array $shown, array $rosterIds): int
    {
        $statuses = $this->statusValues($shown);

        if ($statuses === []) {
            return 0;
        }

        return Intervention::query()
            ->forClass($class->id)
            ->where('available_for_reports', false)
            ->whereIn('status', $statuses)
            ->where(fn ($reach) => $reach
                ->where('target_type', InterventionTargetType::SchoolClass->value)
                ->orWhereHas('participants', fn ($participants) => $participants->whereIn('enrollments.id', $rosterIds))
                ->orWhere(fn ($legacy) => $legacy
                    ->where('target_type', InterventionTargetType::Student->value)
                    ->whereIn('enrollment_id', $rosterIds)
                    ->whereDoesntHave('participants')))
            ->count();
    }

    /**
     * @param  list<MeasureMapColumn>  $shown
     * @return list<string>
     */
    protected function statusValues(array $shown): array
    {
        $values = [];

        foreach ($shown as $column) {
            foreach ($column->statuses() as $status) {
                $values[] = $status->value;
            }
        }

        return $values;
    }

    /**
     * Who the measure names: the pivot, and for a single-student measure whose
     * pivot is empty (older rows) the legacy `enrollment_id`. Never inferred
     * from a NULL.
     *
     * @return list<int>
     */
    protected function participantIds(Intervention $intervention): array
    {
        $ids = $intervention->participants->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if (
            $ids === []
            && $intervention->target_type === InterventionTargetType::Student
            && $intervention->enrollment_id !== null
        ) {
            return [(int) $intervention->enrollment_id];
        }

        return array_values($ids);
    }

    /**
     * One printable item plus the sort key `sorted()` consumes and strips.
     *
     * @return array{label: string, framing: ?string, status: ?string, group: bool, sort: list<int|string>}
     */
    protected function item(Intervention $intervention, MeasureMapColumn $column): array
    {
        $label = $this->label($intervention);
        $legalMeasures = $this->legalMeasures($intervention);

        $framing = [];

        foreach ($legalMeasures as $measure) {
            // «Medida seletiva» alone when the designation already IS the
            // measure's name; the level and the measure otherwise. Saying the
            // same words twice would read as two different facts.
            $framing[] = mb_strtolower($measure['code']->label()) === mb_strtolower($label)
                ? $measure['level']->label()
                : $measure['level']->label().' — '.$measure['code']->label();
        }

        $firstLevel = $legalMeasures[0]['level'] ?? null;
        $levelPosition = $firstLevel === null
            ? count(SupportMeasureLevel::cases())
            : (int) array_search($firstLevel, SupportMeasureLevel::cases(), true);

        return [
            'label' => $label,
            'framing' => $framing === [] ? null : implode('; ', $framing),
            // The real state where the column covers several states; nothing
            // for «A implementar», whose heading already says it.
            'status' => $column === MeasureMapColumn::Implemented ? $intervention->status->label() : null,
            'group' => $intervention->target_type === InterventionTargetType::Group,
            'sort' => [
                $levelPosition,
                Str::ascii(mb_strtolower($label)),
                $intervention->started_on->toDateString(),
                $intervention->id,
            ],
        ];
    }

    /**
     * The designation as it was stamped when the measure was recorded — a
     * catalogue name, and never anything the teacher typed.
     *
     * NO FREE TEXT, NOT EVEN AS A NAME. `pedagogicalTitle()` would be the
     * obvious fallback for «Outro» and for untyped rows, and it reads
     * `strategy_label` and `title` first: the teacher's own words, which on
     * this sheet are exactly what may not travel (a strategy can say why a
     * child needs it). So the sheet names only what the catalogue names:
     *
     *   - a typed row → `typeLabel()`, the snapshot first (an imported measure
     *     without a type of its own carries its legal designation there);
     *   - «Outro» with nothing stamped but «Outro» → «Outra medida»: the
     *     teacher chose it and described it, the description just stays in
     *     Estratégias e Medidas;
     *   - no type at all → «Medida sem tipo registado», the same rule the
     *     relatório de turma follows: an untyped record is counted, never
     *     quoted, and never renamed into something that reads like a category
     *     («Legado sem dominio» reached a printed document that way once).
     *
     * See docs/legal-frameworks.md, «A designação do tipo é estampada».
     */
    protected function label(Intervention $intervention): string
    {
        if ($intervention->intervention_type === null) {
            return __('Medida sem tipo registado');
        }

        $stamped = $intervention->typeLabel();

        if ($stamped === null || $stamped === InterventionType::Other->label()) {
            return $intervention->intervention_type === InterventionType::Other
                ? __('Outra medida')
                : $intervention->intervention_type->label();
        }

        return $stamped;
    }

    /**
     * The legal support measures framing this intervention, with the level as
     * it was STORED — never recomputed from today's law.
     *
     * @return list<array{level: SupportMeasureLevel, code: SupportMeasureCode}>
     */
    protected function legalMeasures(Intervention $intervention): array
    {
        $measures = [];

        foreach ($intervention->supportMeasures as $measure) {
            $measures[] = ['level' => $measure->support_measure_level, 'code' => $measure->support_measure_code];
        }

        if ($measures !== []) {
            return $measures;
        }

        if (
            $intervention->legal_mapping_source !== null
            && $intervention->support_measure_code !== null
            && $intervention->support_measure_level !== null
        ) {
            return [['level' => $intervention->support_measure_level, 'code' => $intervention->support_measure_code]];
        }

        return [];
    }

    /**
     * Items in a stable, legible order: by the level of the first legal
     * measure (universal, selective, additional; unframed last), then name,
     * then start date, then id. The sort key is dropped from the output.
     *
     * @param  list<array{label: string, framing: ?string, status: ?string, group: bool, sort: list<int|string>}>  $items
     * @return list<array{label: string, framing: ?string, status: ?string, group: bool}>
     */
    protected function sorted(array $items): array
    {
        usort($items, fn (array $left, array $right): int => $left['sort'] <=> $right['sort']);

        return array_map(fn (array $item): array => [
            'label' => $item['label'],
            'framing' => $item['framing'],
            'status' => $item['status'],
            'group' => $item['group'],
        ], $items);
    }

    /**
     * Only the letterhead fields this document prints. No logo: the map is a
     * working sheet, and a logo would need bytes the structure does not carry.
     *
     * @return array<string, mixed>
     */
    protected function identityPayload(SchoolClass $class): array
    {
        $identity = $this->identity->for($class->organization);

        return [
            'name' => $identity['name'],
            'header_lines' => $identity['header_lines'],
            'footer_note' => $identity['footer_note'],
            'is_configured' => $identity['is_configured'],
            'logo_url' => null,
            'logo' => null,
        ];
    }

    /**
     * @param  array{students: int, students_with_measures: int, implemented: ?int, planned: ?int}  $summary
     */
    protected function summaryLine(array $summary): string
    {
        $parts = [
            $summary['students'] === 1
                ? __('1 aluno')
                : __(':count alunos', ['count' => $summary['students']]),
            __(':count com medidas', ['count' => $summary['students_with_measures']]),
        ];

        if ($summary['implemented'] !== null) {
            $parts[] = $summary['implemented'] === 1
                ? __('1 implementada')
                : __(':count implementadas', ['count' => $summary['implemented']]);
        }

        if ($summary['planned'] !== null) {
            $parts[] = __(':count a implementar', ['count' => $summary['planned']]);
        }

        return implode(' · ', $parts);
    }

    protected function excludedNote(int $excluded): ?string
    {
        if ($excluded === 0) {
            return null;
        }

        if ($excluded === 1) {
            return __('1 medida desta turma não está disponível para relatórios e não aparece neste mapa.');
        }

        return __(':count medidas desta turma não estão disponíveis para relatórios e não aparecem neste mapa.', ['count' => $excluded]);
    }
}
