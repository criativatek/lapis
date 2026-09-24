<?php

namespace App\Http\Controllers;

use App\Actions\Interventions\CreateIntervention;
use App\Models\CharacterisationRevision;
use App\Models\ClassCharacterisation;
use App\Models\Enrollment;
use App\Models\EnrollmentCharacterisation;
use App\Models\EnrollmentCharacterisationSourceMeasure;
use App\Models\EnrollmentStatus;
use App\Models\Intervention;
use App\Models\InterventionDescriptionSource;
use App\Models\InterventionDomainRelation;
use App\Models\InterventionOrigin;
use App\Models\InterventionStatus;
use App\Models\InterventionSupportMeasure;
use App\Models\InterventionTargetType;
use App\Models\InterventionType;
use App\Models\LegalMappingSource;
use App\Models\SchoolClass;
use App\Models\SupportMeasureCode;
use App\Models\SupportMeasureLevel;
use App\Services\Audit\AuditLog;
use App\Services\Characterisation\RecordCharacterisation;
use App\Support\Characterisation\CharacterisationSection;
use App\Support\Interventions\ActiveSupportMeasures;
use App\Support\Interventions\InterventionAuditProperties;
use App\Support\Interventions\LegalFrameworkResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The pedagogical characterisation of a class and of each student in it.
 *
 * Reached from the class, below the roll — not from a menu of its own. It is
 * something you write ABOUT a class you are already looking at, and a sidebar
 * entry would have made it a separate errand.
 *
 * Authorisation is the class's, throughout: `view` to read, `update` to write.
 * A characterisation is not a thing you can hold a permission to independently
 * of the class it describes, and inventing a second policy would have created
 * a way for the two to disagree.
 */
class ClassCharacterisationController extends Controller
{
    /**
     * How many revisions travel with the page. The panel is closed by default
     * and a teacher opening it wants the recent few, not an archive.
     */
    private const REVISIONS_SHOWN = 10;

    public function __construct(
        private readonly RecordCharacterisation $recorder,
        private readonly AuditLog $audit,
        private readonly LegalFrameworkResolver $frameworks,
        private readonly CreateIntervention $creator,
    ) {}

    public function show(SchoolClass $class): Response
    {
        Gate::authorize('view', $class);

        // Eager-loaded deliberately: a class is thirty students, each with a
        // characterisation, its measures and its history, and letting Blade ask
        // for them one at a time is the N+1 this screen would otherwise be.
        $class->loadMissing(['subject', 'academicYear']);

        // Active enrolments, PLUS any inactive one that already has a
        // characterisation. The importer matches against the whole roll on
        // purpose — a student who left in February was here in November and a
        // row about them is still about them — so showing only the active roll
        // would let a confirmed import land somewhere the teacher could then
        // never see or correct.
        $characterised = EnrollmentCharacterisation::query()->select('enrollment_id');

        $enrollments = $class->enrollments()
            ->where(function ($query) use ($characterised) {
                $query->where('status', EnrollmentStatus::Active)
                    ->orWhereIn('id', $characterised);
            })
            ->with('student.identity')
            ->orderBy('class_number')
            ->get();

        $characterisations = EnrollmentCharacterisation::query()
            ->whereIn('enrollment_id', $enrollments->pluck('id'))
            ->with([
                'updatedBy',
                'sourceMeasures',
                // Capped: the history is closed by default on screen, and
                // shipping a year of every student's edits in the initial
                // payload would make the page grow without limit for a panel
                // most visits never open.
                'revisions' => fn ($query) => $query->with('author')->limit(self::REVISIONS_SHOWN),
            ])
            ->get()
            ->keyBy('enrollment_id');

        $classCharacterisation = ClassCharacterisation::query()
            ->where('class_id', $class->getKey())
            ->with(['updatedBy', 'revisions' => fn ($query) => $query->with('author')->limit(self::REVISIONS_SHOWN)])
            ->first();

        // One query for every student's structured measures, not one per row
        // (§2 of the brief) — grouped in memory afterwards. Every ORIGIN is
        // included: a measure a teacher registered by hand in Estratégias e
        // Medidas is just as much "an associated measure" here as one the
        // import created, which is exactly the duplication this replaces
        // (there is no longer a second, import-only list). A measure lives
        // either on the parent's own column or exclusively in the pivot (a
        // hand-created intervention with several measures stores only the
        // first on the parent — see ActiveSupportMeasures), so both are
        // matched here.
        $interventionsByEnrollment = Intervention::query()
            ->whereIn('enrollment_id', $enrollments->pluck('id'))
            ->where(function ($query): void {
                $query->whereNotNull('support_measure_code')
                    ->orWhereHas('supportMeasures');
            })
            ->with('supportMeasures')
            ->get()
            ->groupBy('enrollment_id');

        // The framework resolved to TODAY: this is what governs whether a NEW
        // measure can be added right now, and what catalogue the "Adicionar
        // medida" dialog offers — never re-derived per intervention, which
        // would misreport an organization with no taxonomy at all as simply
        // having none of today's levels.
        $framework = $this->frameworks->for($class->organization, Carbon::now());

        // Source measures keyed by (enrollment_id, code): the only thing a
        // structured measure may still borrow from the import record is the
        // raw token it was read from, when one exists for the same code.
        $sourceMeasuresByEnrollmentAndCode = [];
        foreach ($characterisations as $characterisation) {
            foreach ($characterisation->sourceMeasures as $sourceMeasure) {
                if ($sourceMeasure->support_measure_code === null) {
                    continue;
                }

                $sourceMeasuresByEnrollmentAndCode[$characterisation->enrollment_id][$sourceMeasure->support_measure_code->value] = $sourceMeasure->raw_token;
            }
        }

        return Inertia::render('classes/Characterisation', [
            'schoolClass' => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject?->name,
                'academic_year' => $class->academicYear?->label,
            ],
            'classCharacterisation' => [
                'summary' => $classCharacterisation?->summary,
                'last_updated_at' => $classCharacterisation?->last_updated_at?->toIso8601String(),
                'updated_by' => $classCharacterisation?->updatedBy?->name,
                'revisions' => $this->revisions($classCharacterisation === null ? collect() : $classCharacterisation->revisions),
            ],
            'students' => $enrollments->map(function (Enrollment $enrollment) use ($characterisations, $interventionsByEnrollment, $sourceMeasuresByEnrollmentAndCode) {
                $characterisation = $characterisations->get($enrollment->getKey());

                return [
                    'enrollment_ulid' => $enrollment->ulid,
                    'name' => $enrollment->student?->identity->display_name ?? __('(sem identidade)'),
                    'class_number' => $enrollment->class_number,
                    'sections' => $this->sections($characterisation),
                    'has_characterisation' => $characterisation?->hasAnySection() ?? false,
                    'last_updated_at' => $characterisation?->last_updated_at?->toIso8601String(),
                    'updated_by' => $characterisation?->updatedBy?->name,
                    'measures' => $this->measuresFor(
                        $interventionsByEnrollment->get($enrollment->getKey(), collect()),
                        $sourceMeasuresByEnrollmentAndCode[$enrollment->getKey()] ?? [],
                    ),
                    'unresolved_annotations' => $characterisation === null ? [] : $characterisation->sourceMeasures
                        ->flatMap(fn (EnrollmentCharacterisationSourceMeasure $measure) => $measure->unresolved_annotations ?? [])
                        ->unique()->values()->all(),
                    'revisions' => $this->revisions($characterisation === null ? collect() : $characterisation->revisions),
                ];
            })->values()->all(),
            'sections' => array_map(
                fn (CharacterisationSection $section) => [
                    'key' => $section->value,
                    'label' => $section->label(),
                ],
                CharacterisationSection::cases(),
            ),
            'supportMeasureLevels' => $framework->supportMeasureLevels(),
            'can' => [
                'update' => Gate::allows('update', $class),
                // The catalogue comes from the framework resolved above: with
                // no legal taxonomy there is nothing to pick from, so the
                // "Adicionar medida" action has no useful destination and
                // stays hidden rather than opening onto an empty dialog.
                'addMeasure' => Gate::allows('update', $class) && $framework->hasLegalTaxonomy(),
            ],
        ]);
    }

    /**
     * One enrolment's associated measures — every `InterventionSupportMeasure`
     * across every structured Intervention it carries, from EITHER origin
     * (§2 of the brief: this replaces the import-only "Medidas de origem"
     * block, it does not sit beside it). Several measures on one intervention
     * — or several interventions naming the same level's measures — each
     * produce their own row, ordered by level then code then start date so
     * the list reads stably across reloads.
     *
     * @param  Collection<int, Intervention>  $interventions
     * @param  array<string, string>  $rawTokensByCode
     * @return list<array<string, mixed>>
     */
    private function measuresFor(Collection $interventions, array $rawTokensByCode): array
    {
        $rows = [];

        foreach ($interventions as $intervention) {
            $pairs = $intervention->supportMeasures->isNotEmpty()
                ? $intervention->supportMeasures->map(fn (InterventionSupportMeasure $measure) => [
                    'level' => $measure->support_measure_level,
                    'code' => $measure->support_measure_code,
                ])->all()
                : ($intervention->support_measure_code === null ? [] : [[
                    'level' => $intervention->support_measure_level,
                    'code' => $intervention->support_measure_code,
                ]]);

            foreach ($pairs as $pair) {
                $rows[] = [
                    // Não emitido: só ordena. Ver a nota do usort() abaixo.
                    '_level_rank' => $pair['level'] === null ? PHP_INT_MAX : array_search($pair['level'], SupportMeasureLevel::cases(), true),
                    'ulid' => $intervention->ulid,
                    'level_label' => $pair['level']?->label(),
                    'code_label' => $pair['code']->label(),
                    'origin' => ($intervention->origin ?? InterventionOrigin::Manual)->value,
                    'origin_label' => ($intervention->origin ?? InterventionOrigin::Manual)->label(),
                    'status_label' => $intervention->status->label(),
                    'started_on' => $intervention->started_on->toDateString(),
                    'raw_token' => $rawTokensByCode[$pair['code']->value] ?? null,
                ];
            }
        }

        // Universal → seletiva → adicional, que é a ordem em que o regime
        // as apresenta e a ordem em que um professor as lê — NÃO a ordem
        // alfabética das etiquetas, que poria «adicional» primeiro e faria a
        // lista parecer ordenada por severidade decrescente. A sequência vem
        // da declaração do enum, não de uma lista repetida aqui: um nível
        // acrescentado ao regime entra na posição certa sem tocar neste
        // ficheiro. Dentro de cada nível, alfabética pela etiqueta traduzida,
        // que é o que a lista mostra; `started_on` desempata só para a ordem
        // não oscilar entre recarregamentos.
        usort($rows, fn (array $a, array $b) => [$a['_level_rank'], $a['code_label'], $a['started_on']] <=> [$b['_level_rank'], $b['code_label'], $b['started_on']]);

        return array_map(function (array $row): array {
            unset($row['_level_rank']);

            return $row;
        }, $rows);
    }

    /**
     * The class's own characterisation — the secondary one.
     */
    public function update(Request $request, SchoolClass $class): RedirectResponse
    {
        Gate::authorize('update', $class);

        $data = $request->validate([
            'summary' => ['nullable', 'string', 'max:5000'],
        ]);

        // Found, or built and NOT saved. `firstOrCreate` here would leave a row
        // behind for a class somebody merely opened, and an empty row is not
        // nothing: it is a foreign key that blocks the class from ever being
        // deleted. RecordCharacterisation saves only when something changed.
        $characterisation = ClassCharacterisation::query()->firstOrNew(['class_id' => $class->getKey()]);

        $changed = $this->recorder->apply($characterisation, $data, $request->user());

        if ($changed !== []) {
            $this->audit->record(
                'characterisation.class.updated',
                $class,
                $request->user(),
                "Caracterização da turma {$class->label} atualizada.",
                ['class_id' => $class->id, 'changed' => $changed],
            );
        }

        return back();
    }

    /**
     * One student's characterisation, saved on its own.
     *
     * Per student rather than a whole-class form: thirty students' text in one
     * request would make one teacher's save silently overwrite another's, and
     * a class is very often taught by two people.
     */
    public function updateStudent(Request $request, SchoolClass $class, Enrollment $enrollment): RedirectResponse
    {
        Gate::authorize('update', $class);

        // The enrolment must belong to THIS class. A ULID from another class in
        // the same organization is a 404 rather than a 403: confirming that the
        // enrolment exists elsewhere would say more than the asker is entitled
        // to know (ADR-0002).
        abort_unless($enrollment->class_id === $class->getKey(), 404);

        $data = $request->validate(
            array_fill_keys(
                array_map(fn (string $key) => $key, CharacterisationSection::keys()),
                ['nullable', 'string', 'max:5000'],
            ),
        );

        // Built, not created — see the note in update(). An empty row for a
        // student whose form was opened and closed would block that student
        // from ever being removed from the class, which is a dead end this
        // application has as a rule not to introduce.
        $characterisation = EnrollmentCharacterisation::query()->firstOrNew(['enrollment_id' => $enrollment->getKey()]);

        $changed = $this->recorder->apply($characterisation, $data, $request->user());

        if ($changed !== []) {
            $this->audit->record(
                'characterisation.student.updated',
                $enrollment,
                $request->user(),
                "Caracterização pedagógica atualizada — {$class->label}.",
                [
                    'class_id' => $class->id,
                    'enrollment_id' => $enrollment->id,
                    // The sections that changed, never their contents: an audit
                    // trail that copied the text would be a second, unbounded
                    // store of what the teacher wrote about a child.
                    'changed' => $changed,
                ],
            );
        }

        return back();
    }

    /**
     * A measure registered by hand from the Caracterização card — the manual
     * counterpart to `ApplyCharacterisationImport::createInterventions()`.
     * Both write the same thing: a structured `Intervention` carrying an
     * `InterventionSupportMeasure`, visible from Estratégias e Medidas as
     * much as from here (§3 of the brief — there is deliberately no second
     * measures table for this screen).
     *
     * THE LEVEL IS NEVER TAKEN FROM THE CLIENT. It is derived from the
     * framework resolved to TODAY, exactly like `InterventionController`'s
     * own "automatic"/"manual" framing resolution — the law's answer, not a
     * value a form field could get out of sync with it.
     */
    public function storeMeasure(Request $request, SchoolClass $class, Enrollment $enrollment): RedirectResponse
    {
        Gate::authorize('update', $class);

        // Same ADR-0002 note as updateStudent(): an enrolment from another
        // class is a 404, never a 403.
        abort_unless($enrollment->class_id === $class->getKey(), 404);

        $validated = $request->validate([
            'support_measure_code' => ['required', Rule::enum(SupportMeasureCode::class)],
        ]);

        $code = SupportMeasureCode::from($validated['support_measure_code']);
        $framework = $this->frameworks->for($class->organization, Carbon::now());
        $level = $framework->hasLegalTaxonomy() ? $framework->levelFor($code) : null;

        if ($level === null) {
            throw ValidationException::withMessages([
                'support_measure_code' => __('Esta medida não tem nível no enquadramento legal aplicável hoje e não pode ser associada.'),
            ]);
        }

        if (ActiveSupportMeasures::exists($enrollment, $code)) {
            throw ValidationException::withMessages([
                'support_measure_code' => __('Esta medida já está associada a este aluno.'),
            ]);
        }

        $intervention = DB::transaction(fn () => $this->creator->create(
            class: $class,
            attributes: [
                'enrollment_id' => $enrollment->getKey(),
                'target_type' => InterventionTargetType::Student,
                'intervention_type' => InterventionType::tryFrom($code->value) ?? InterventionType::Other,
                'intervention_type_label' => $code->label(),
                'domain_relation' => InterventionDomainRelation::None,
                'title' => $code->label(),
                'description' => null,
                'description_source' => InterventionDescriptionSource::Manual,
                'status' => InterventionStatus::New,
                'started_on' => Carbon::today()->toDateString(),
                'available_for_reports' => true,
                'include_in_report' => true,
                'support_measure_level' => $level,
                'support_measure_code' => $code,
                'legal_mapping_source' => LegalMappingSource::Manual,
                'legal_framework_code' => $framework->code(),
                'origin' => InterventionOrigin::Manual,
            ],
            supportMeasures: [['level' => $level->value, 'code' => $code->value]],
            participantIds: [$enrollment->getKey()],
            createdBy: $request->user(),
        ));

        // Recorded AFTER the transaction commits, exactly like
        // InterventionController::store() — never inside it, so the audit
        // trail can never claim an intervention exists that a rollback just
        // undid.
        $this->audit->record(
            'intervention.created',
            $intervention,
            $request->user(),
            __('Medida associada a partir da caracterização pedagógica.'),
            [
                ...InterventionAuditProperties::base($intervention),
                'enrollment_id' => $enrollment->getKey(),
                'support_measure_level' => $level->value,
                'support_measure_code' => $code->value,
            ],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Medida associada.')]);

        return back();
    }

    /**
     * @param  iterable<CharacterisationRevision>  $revisions
     * @return list<array<string, mixed>>
     */
    private function revisions(iterable $revisions): array
    {
        $rows = [];

        foreach ($revisions as $revision) {
            $rows[] = [
                'ulid' => $revision->ulid,
                'created_at' => $revision->created_at->toIso8601String(),
                'author' => $revision->author?->name,
                'source_label' => $revision->source->label(),
                'changed_labels' => array_map(
                    fn (string $key) => CharacterisationSection::tryFrom($key)?->label() ?? $key,
                    $revision->changed_sections,
                ),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, string|null>
     */
    private function sections(?EnrollmentCharacterisation $characterisation): array
    {
        $sections = [];

        foreach (CharacterisationSection::keys() as $key) {
            $sections[$key] = $characterisation?->{$key};
        }

        return $sections;
    }
}
