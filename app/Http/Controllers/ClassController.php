<?php

namespace App\Http\Controllers;

use App\Actions\Classes\ArchiveSchoolClass;
use App\Http\Requests\ClassRequest;
use App\Models\AcademicYear;
use App\Models\AssessmentProfile;
use App\Models\AssessmentProfileVersion;
use App\Models\ClassGroup;
use App\Models\Classification;
use App\Models\ClassStatus;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\ProfileVersionStatus;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Rules\BelongsToCurrentOrganization;
use App\Services\Classes\ClassRoster;
use App\Services\Classes\SchoolClassHistory;
use App\Services\ClassService;
use App\Services\EnrollmentHistory;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ClassController extends Controller
{
    public function __construct(
        protected ClassService $service,
        protected Entitlements $entitlements,
        protected CurrentOrganization $currentOrganization,
        protected ArchiveSchoolClass $archiveSchoolClass,
    ) {}

    public function index(): Response
    {
        Gate::authorize('viewAny', SchoolClass::class);

        // Only the teacher's own classes (§23). Tenant isolation plus class_teachers.
        // Arquivadas ficam de fora por omissão — têm a sua própria lista, em
        // classes.archived.
        $classes = $this->teacherClasses()
            ->notArchived()
            ->with(['subject', 'academicYear'])
            ->withCount('enrollments')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (SchoolClass $class) => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'academic_year' => $class->academicYear->label,
                'grade_level' => $class->grade_level,
                'status_label' => $class->status->label(),
                'status' => $class->status->value,
                'students_count' => $class->enrollments_count,
            ]);

        return Inertia::render('classes/Index', ['classes' => $classes, 'viewingArchived' => false]);
    }

    /**
     * «Turmas arquivadas» — a mesma lista, ao contrário, com a informação de
     * retenção que a turma ativa não precisa de mostrar.
     */
    public function archived(): Response
    {
        Gate::authorize('viewAny', SchoolClass::class);

        $classes = $this->teacherClasses()
            ->archivedOnly()
            ->with(['subject', 'academicYear'])
            ->withCount('enrollments')
            ->orderByDesc('archived_at')
            ->get()
            ->map(fn (SchoolClass $class) => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'academic_year' => $class->academicYear->label,
                'grade_level' => $class->grade_level,
                'status_label' => $class->status->label(),
                'status' => $class->status->value,
                'students_count' => $class->enrollments_count,
                'archived_at' => $class->archived_at?->toDateString(),
                'eligible_for_deletion_at' => $class->eligibleForPermanentDeletionAt()?->toDateString(),
                'is_eligible_for_deletion' => $class->isEligibleForPermanentDeletion(),
            ]);

        return Inertia::render('classes/Index', ['classes' => $classes, 'viewingArchived' => true]);
    }

    /**
     * "Configurar horários" — the front door onto both ways a teacher fills in
     * a turma's schedule: importing a PDF (timetable-imports.create, unchanged)
     * or configuring one turma at a time by hand on its own page
     * (LessonScheduleEditor, unchanged). This picker creates nothing itself —
     * it only points at the two existing flows.
     */
    public function scheduleSetup(): Response
    {
        Gate::authorize('viewAny', SchoolClass::class);

        $classes = $this->teacherClasses()
            ->with('subject')
            ->orderBy('label')
            ->get()
            ->map(fn (SchoolClass $class) => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
            ])
            ->values();

        return Inertia::render('classes/ScheduleSetup', ['classes' => $classes]);
    }

    public function create(): Response
    {
        Gate::authorize('create', SchoolClass::class);

        return Inertia::render('classes/Create', $this->formOptions());
    }

    public function store(ClassRequest $request): RedirectResponse
    {
        Gate::authorize('create', SchoolClass::class);

        $class = $this->service->create(
            [...$request->safe()->only(['label', 'academic_year_id', 'subject_id', 'grade_level', 'assessment_profile_version_id']), 'status' => 'preparation'],
            $this->user(),
        );

        return to_route('classes.show', $class->ulid);
    }

    public function show(SchoolClass $class, EnrollmentHistory $history, ClassRoster $roster): Response
    {
        Gate::authorize('view', $class);

        $class->load(['subject', 'academicYear', 'profileVersion.profile']);

        $timezone = $this->currentOrganization->get()->timezone;
        $today = CarbonImmutable::now($timezone)->toDateString();

        $enrollmentsWithHistory = $history->idsWithHistoryIn($class);

        // Os grupos e a sua composição DE HOJE, em duas consultas para a turma
        // inteira — nunca uma por grupo nem uma por aluno (§ ClassRoster).
        // `withCount('memberships')` daria o número errado de propósito: contaria
        // também as janelas já fechadas, e «T1 (14)» num grupo de oito alunos é
        // pior do que nenhum número.
        $hasLessonsModule = $this->entitlements->allows('lessons');
        $classGroups = $hasLessonsModule ? $class->classGroups()->get() : collect();
        // «Hoje», mas dentro do ano letivo desta turma: em setembro, antes de o
        // ano abrir, «quem está em T1» só pode querer dizer «quem vai estar»
        // (§ ClassRoster::readingDateFor()). Sem isto o ecrã dizia «0 alunos»
        // logo a seguir a o professor os ter distribuído.
        $rosterDate = $hasLessonsModule ? $roster->readingDateFor($class, $today) : $today;
        $composition = $hasLessonsModule
            ? $roster->compositionFor(
                $class,
                array_values(array_map(intval(...), $class->activeEnrollments()->pluck('id')->all())),
                $rosterDate,
            )
            : ['groups' => [], 'counts' => [], 'since' => []];
        $memberCounts = $composition['counts'];
        $groupByEnrollment = $composition['groups'];
        $groupSince = $composition['since'];
        $groupLabelsById = $classGroups->pluck('label', 'id');

        return Inertia::render('classes/Show', [
            'schoolClass' => [
                'ulid' => $class->ulid,
                'id' => $class->id,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'academic_year' => $class->academicYear->label,
                'grade_level' => $class->grade_level,
                'status' => $class->status->value,
                'status_label' => $class->status->label(),
                'profile_name' => $class->profileVersion?->profile->name,
                'subject_id' => $class->subject_id,
                'archived' => $class->isArchived(),
                'archived_at' => $class->archived_at?->toDateString(),
                'eligible_for_deletion_at' => $class->eligibleForPermanentDeletionAt()?->toDateString(),
                'is_eligible_for_deletion' => $class->isEligibleForPermanentDeletion(),
            ],
            // Active profiles for this subject, so a class created without one can
            // be assigned later without going back to the profile screen.
            'availableProfiles' => AssessmentProfile::whereNotNull('current_version_id')
                ->where('subject_id', $class->subject_id)
                ->get()
                ->map(fn (AssessmentProfile $profile) => [
                    'version_id' => $profile->current_version_id,
                    'label' => $profile->name,
                ]),
            // Only the currently-active-or-future slot per schedule line: a
            // revision (ReviseRecurringLessonSlot) leaves the old, now-closed
            // row in place for Lessons already materialized from it to keep
            // pointing at, and without this filter it would reappear here
            // mixed in with the version that replaced it.
            'recurringLessonSlots' => $hasLessonsModule
                ? $class->recurringLessonSlots()
                    ->where(fn (Builder $query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
                    ->orderBy('day_of_week')->orderBy('starts_at')->get()->map(
                        fn (RecurringLessonSlot $slot) => [
                            'ulid' => $slot->ulid,
                            'day_of_week' => $slot->day_of_week,
                            'starts_at' => substr($slot->starts_at, 0, 5),
                            'ends_at' => substr($slot->ends_at, 0, 5),
                            'starts_on' => $slot->starts_on?->toDateString(),
                            'ends_on' => $slot->ends_on?->toDateString(),
                            'already_in_vigor' => $slot->isAlreadyInVigor($timezone),
                            'requires_versioning' => $slot->requiresVersioning($timezone),
                            // NULL = turma inteira, e é o que todos os tempos já
                            // existentes dizem. O id vai a par do rótulo porque o
                            // seletor do editor de horário casa por id.
                            'class_group_id' => $slot->class_group_id,
                            'class_group_label' => $slot->class_group_id === null
                                ? null
                                : $groupLabelsById[$slot->class_group_id] ?? null,
                        ],
                    )->values()
                : null,
            // A secção «Grupos». `null` — e não uma lista vazia — quando o
            // módulo das aulas não está no plano: é o mesmo sinal que
            // `recurringLessonSlots` dá, e é o que faz a secção inteira não
            // existir em vez de aparecer vazia a convidar a um clique que
            // seria recusado no servidor.
            // A data que os diálogos «Mover» e «Permutar» oferecem por omissão.
            // Vem do servidor e não do relógio do browser porque tem de cair
            // dentro do ano letivo desta turma: em setembro, antes de o ano
            // abrir, «hoje» seria recusado pela própria ação.
            'classGroupsDefaultDate' => $hasLessonsModule ? $rosterDate : null,
            'classGroups' => $hasLessonsModule
                ? $classGroups->map(fn (ClassGroup $group) => [
                    'ulid' => $group->ulid,
                    'id' => $group->id,
                    'label' => $group->label,
                    'position' => $group->position,
                    'archived' => $group->isArchived(),
                    'members_count' => $memberCounts[$group->id] ?? 0,
                ])->values()
                : null,
            // Names come from the encrypted identity — shown to the class's own
            // teacher, who is authorized. The pseudonym is what leaves the app.
            // THE CLASS AS IT STANDS. Somebody the roll says has transferred,
            // moved class, cancelled or been excluded is not part of the group
            // a teacher works with today — and is not deleted either: they are
            // listed below, under their own heading (§4, §13).
            // QUEM JÁ NÃO SE PODE REMOVER, dito antes de o professor tentar.
            //
            // Dez queries com um `IN` para a turma inteira — nunca uma por
            // aluno (EnrollmentHistory::idsWithHistoryIn explica porquê). É
            // barato o suficiente para caber aqui, e é o que evita que um
            // professor carregue três vezes no mesmo botão sem perceber
            // porque nada acontece, que foi o que aconteceu em produção.
            //
            // ISTO É APRESENTAÇÃO. A autoridade continua a ser
            // EnrollmentController::destroy(), que faz a pergunta outra vez: um
            // separador aberto há uma hora mostra o botão como estava e
            // continua a não apagar nada (§8.2).
            'students' => $class->activeEnrollments()->with('student.identity')->orderBy('class_number')->get()
                ->map(fn (Enrollment $enrollment) => [
                    'ulid' => $enrollment->ulid,
                    // O id numérico é o que a secção «Grupos» envia de volta:
                    // a atribuição, a mudança e a permuta falam de inscrições,
                    // e o `BelongsToCurrentOrganization` dos form requests
                    // resolve-as pela chave primária. Já é assim que
                    // `schoolClass.id` chega ao editor de horário.
                    'id' => $enrollment->id,
                    'can_be_removed' => ! in_array($enrollment->getKey(), $enrollmentsWithHistory, true),
                    // A que grupo pertence HOJE — null é «Sem grupo», que é um
                    // estado legítimo e com nome, e não uma configuração por
                    // acabar (§9 do briefing).
                    'class_group_id' => $groupByEnrollment[$enrollment->id] ?? null,
                    // Desde quando essa pertença vale — a data que o diálogo
                    // «Mover» oferece. Num aluno de ingresso tardio é o dia em
                    // que ele entrou, e não o início do ano letivo.
                    'class_group_since' => $groupSince[$enrollment->id] ?? null,
                    'name' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)',
                    // So the edit dialog opens on an empty field instead of
                    // offering "(sem identidade)" as if it were a real name.
                    'has_identity' => $enrollment->student->identity !== null,
                    'pseudonym' => $enrollment->student->pseudonym_code,
                    // The school's own identifier, when there is one. Optional
                    // everywhere except an export that needs it.
                    'process_number' => $enrollment->student->processNumber(),
                    'class_number' => $enrollment->class_number,
                    'enrolled_on' => $enrollment->enrolled_on->toDateString(),
                    'is_late_entry' => $enrollment->is_late_entry,
                    'status_label' => $enrollment->status->label(),
                    'photo_url' => $enrollment->student->photoUrl(),
                ]),

            // NOT DELETED, JUST NOT HERE ANY MORE. Kept visible so a teacher
            // who imported a roll and lost three names can see where they
            // went, and named by WHY they left rather than by a status code
            // (§13, §14). One query, ordered like the roll itself.
            'former_students' => $class->enrollments()
                ->whereNot('status', EnrollmentStatus::Active)
                ->with('student.identity')
                ->orderBy('class_number')
                ->get()
                ->map(fn (Enrollment $enrollment) => [
                    'ulid' => $enrollment->ulid,
                    'name' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)',
                    'class_number' => $enrollment->class_number,
                    // The reason when the roll gave one, the status otherwise —
                    // «Mudou de turma», never «moved_class».
                    'state_label' => $enrollment->status_reason?->label() ?? $enrollment->status->label(),
                ]),
        ]);
    }

    public function edit(SchoolClass $class): Response
    {
        Gate::authorize('update', $class);

        $class->load(['subject', 'academicYear']);

        return Inertia::render('classes/Edit', [
            'schoolClass' => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                // Shown for context, not editable here (§10.2 — year, subject
                // and grade feed reporting/profile-matching and are not safe
                // to change once a class has enrollments or instruments).
                'subject' => $class->subject->name,
                'academic_year' => $class->academicYear->label,
                'grade_level' => $class->grade_level,
            ],
        ]);
    }

    public function update(ClassRequest $request, SchoolClass $class): RedirectResponse
    {
        Gate::authorize('update', $class);

        // Only the label is editable from this form — academic_year_id,
        // subject_id and grade_level are deliberately never read from the
        // request here, regardless of what ClassRequest validated, so a
        // crafted payload cannot move a class between years/subjects.
        $class->update(['label' => $request->validated('label')]);

        return to_route('classes.show', $class->ulid);
    }

    /**
     * Assign (or change) the profile version a class is assessed by.
     *
     * ponytail: a plain reassignment while no results exist. Once results hang
     * off the class, §10.2 requires this to become an explicit migration with an
     * impact preview, recorded in class_profile_migrations — hence the guard.
     */
    public function updateProfile(Request $request, SchoolClass $class): RedirectResponse
    {
        Gate::authorize('update', $class);

        $data = $request->validate([
            'assessment_profile_version_id' => ['required', new BelongsToCurrentOrganization(AssessmentProfileVersion::class)],
        ]);

        // whereKey()->firstOrFail(), not findOrFail(): findOrFail also accepts an
        // array of ids, so its return type is a model-or-collection union.
        $version = AssessmentProfileVersion::whereKey($data['assessment_profile_version_id'])->firstOrFail();

        if ($version->status !== ProfileVersionStatus::Active) {
            return back()->withErrors(['assessment_profile_version_id' => __('Só um perfil ativo pode ser associado a uma turma.')]);
        }

        if ($version->id === $class->assessment_profile_version_id) {
            return back();
        }

        // A class that already has classifications cannot swap versions silently
        // (§10.2, A4): route through the auditable migration, which previews the
        // impact and records a reason. Only a class with no decisions swaps freely.
        $hasDecisions = Classification::query()
            ->whereIn('enrollment_id', $class->enrollments()->select('id'))
            ->exists();

        if ($hasDecisions) {
            return redirect()->route('classes.profile-migration.create', [
                'class' => $class->ulid,
                'to' => $version->ulid,
            ]);
        }

        $class->update(['assessment_profile_version_id' => $version->id]);

        return back();
    }

    public function activate(SchoolClass $class): RedirectResponse
    {
        Gate::authorize('update', $class);

        $class->update(['status' => ClassStatus::Active]);

        return back();
    }

    public function archive(SchoolClass $class): RedirectResponse
    {
        Gate::authorize('archive', $class);

        $this->archiveSchoolClass->execute($class);

        return back();
    }

    public function restore(SchoolClass $class): RedirectResponse
    {
        Gate::authorize('restore', $class);

        $this->archiveSchoolClass->restore($class);

        return back();
    }

    /**
     * Eliminar em definitivo — só depois de arquivada, só depois dos três
     * anos de retenção (SchoolClass::eligibleForPermanentDeletionAt) e só se
     * não sobrar história pedagógica nenhuma (SchoolClassHistory::blocking).
     *
     * TRÊS RECUSAS, TRÊS FRASES — nunca um 403 nem um 409. A mesma disciplina
     * de EnrollmentController::destroy(): uma restrição de negócio conhecida
     * chega como um toast normal, não como um erro genérico.
     */
    public function destroy(SchoolClass $class, SchoolClassHistory $history): RedirectResponse
    {
        Gate::authorize('delete', $class);

        if (! $class->isArchived()) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Arquive a turma antes de a eliminar definitivamente.',
            ]);

            return back();
        }

        $eligibleAt = $class->eligibleForPermanentDeletionAt();

        if ($eligibleAt !== null && CarbonImmutable::now('Europe/Lisbon')->startOfDay()->lt($eligibleAt->startOfDay())) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => "Esta turma poderá ser eliminada definitivamente a partir de {$eligibleAt->format('d/m/Y')}.",
            ]);

            return back();
        }

        $blocking = $history->blocking($class);

        if ($blocking !== []) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => $history->explain($class->label, $blocking),
            ]);

            return back();
        }

        DB::transaction(function () use ($class): void {
            $class->delete();
        });

        return to_route('classes.index');
    }

    /**
     * @return array<string, mixed>
     */
    protected function formOptions(): array
    {
        return [
            'academicYears' => AcademicYear::orderByDesc('starts_on')->get(['id', 'label'])
                ->map(fn (AcademicYear $year) => ['id' => $year->id, 'label' => $year->label]),
            'subjects' => Subject::orderBy('name')->get(['id', 'name'])
                ->map(fn (Subject $subject) => ['id' => $subject->id, 'label' => $subject->name]),
            // Only activated profiles can be assigned — a class is assessed by an
            // active (frozen) version, never a draft.
            'profiles' => AssessmentProfile::whereNotNull('current_version_id')
                ->with('subject')
                ->get()
                ->map(fn (AssessmentProfile $profile) => [
                    'version_id' => $profile->current_version_id,
                    'label' => $profile->name,
                    'subject_id' => $profile->subject_id,
                ]),
        ];
    }

    /**
     * The turmas this teacher teaches (§23): the current organization's own
     * (SchoolClass's own global scope) via class_teachers, and nothing else.
     * Shared by index() and scheduleSetup() so this scoping is defined in
     * exactly one place rather than reimplemented per entry point.
     *
     * The question itself now lives on the model (SchoolClass::scopeTaughtBy),
     * because «Horário do Professor» asks it too and the two must never
     * disagree about whose turmas they are. The query is byte for byte the one
     * this method has always built.
     *
     * @return Builder<SchoolClass>
     */
    protected function teacherClasses(): Builder
    {
        return SchoolClass::query()->taughtBy($this->user());
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
