<?php

namespace App\Http\Controllers;

use App\Models\CharacterisationRevision;
use App\Models\ClassCharacterisation;
use App\Models\Enrollment;
use App\Models\EnrollmentCharacterisation;
use App\Models\EnrollmentCharacterisationSourceMeasure;
use App\Models\EnrollmentStatus;
use App\Models\SchoolClass;
use App\Services\Audit\AuditLog;
use App\Services\Characterisation\RecordCharacterisation;
use App\Support\Characterisation\CharacterisationSection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
            'students' => $enrollments->map(function (Enrollment $enrollment) use ($characterisations) {
                $characterisation = $characterisations->get($enrollment->getKey());

                return [
                    'enrollment_ulid' => $enrollment->ulid,
                    'name' => $enrollment->student?->identity->display_name ?? __('(sem identidade)'),
                    'class_number' => $enrollment->class_number,
                    'sections' => $this->sections($characterisation),
                    'has_characterisation' => $characterisation?->hasAnySection() ?? false,
                    'last_updated_at' => $characterisation?->last_updated_at?->toIso8601String(),
                    'updated_by' => $characterisation?->updatedBy?->name,
                    'source_measures' => $characterisation === null ? [] : $characterisation->sourceMeasures
                        ->map(fn (EnrollmentCharacterisationSourceMeasure $measure) => [
                            'ulid' => $measure->ulid,
                            'level_label' => $measure->support_measure_level?->label(),
                            'code_label' => $measure->support_measure_code?->label(),
                            'raw_token' => $measure->raw_token,
                            'unresolved_annotations' => $measure->unresolved_annotations ?? [],
                        ])->values()->all(),
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
            'can' => [
                'update' => Gate::allows('update', $class),
            ],
        ]);
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

        $characterisation = ClassCharacterisation::firstOrCreate(['class_id' => $class->getKey()]);

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

        $characterisation = EnrollmentCharacterisation::firstOrCreate(['enrollment_id' => $enrollment->getKey()]);

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
