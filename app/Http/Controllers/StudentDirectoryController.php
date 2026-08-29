<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Support\Privacy\BlindIndex;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Alunos» — the directory: find somebody, see where they are, go there.
 *
 * NOT A SECOND FICHA DO ALUNO, and the whole design follows from that. Two
 * screens already exist around a student and neither is replaced here:
 *
 *   - «Acompanhamento → Aluno» (StudentProgressController::student) is the
 *     pedagogical reading of one person — results, evolução, o ano inteiro.
 *   - «Turmas → turma» (ClassController::show) is the administrative home of
 *     the enrolment: editar um nome, o N.º de processo, a fotografia, importar
 *     uma relação de turma, remover alguém da turma.
 *
 * This page is the INDEX over both. It answers «onde está o João?» and then
 * hands the question over: it writes nothing, it interprets nothing, and it
 * deliberately duplicates no action either of the other two owns.
 *
 * WHAT A TEACHER SEES IS THEIR OWN TURMAS' STUDENTS, never the organization's.
 * Tenancy alone would show a colleague's roll to anybody in the same school, and
 * «só as minhas turmas» (§23) is the rule everywhere else in the app —
 * `SchoolClass::scopeTaughtBy()` is the same scope Turmas and «Horário do
 * Professor» already use, so the three cannot disagree about whose classes
 * these are.
 *
 * THE LISTING STARTS AT `Student`, NEVER AT `StudentIdentity`. Student carries
 * the organization global scope; StudentIdentity deliberately does not — its
 * organization_id is duplicated for its own Policy's sake, and the scope was
 * never added. A directory rooted in the identity table would have been one
 * forgotten `where` away from listing another school's children, so every query
 * here that touches it says `organization_id` out loud.
 */
class StudentDirectoryController extends Controller
{
    /**
     * A page of the directory. Deliberately not "all of them": every row costs
     * one decrypted identity, and a teacher's roll is hundreds of real people.
     */
    protected const PER_PAGE = 100;

    public function __construct(protected CurrentOrganization $currentOrganization) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', SchoolClass::class);

        // The boundary of everything below — the turmas this teacher teaches,
        // already inside the organization scope. Loaded once: it is the filter
        // catalogue, the enrolment whitelist AND the empty-state answer to «tem
        // turmas?». Asking the database three times for one fact would be three
        // chances to answer it differently.
        $visibleClasses = SchoolClass::query()
            ->taughtBy($this->user())
            ->with(['subject', 'academicYear'])
            ->get()
            ->sortBy([['academic_year_id', 'desc'], ['label', 'asc']])
            ->values();

        $visibleClassIds = $visibleClasses->modelKeys();

        $term = trim((string) $request->query('q', ''));
        $class = $this->resolveClassFilter($request, $visibleClasses);
        $academicYear = $this->resolveYearFilter($request, $visibleClasses);
        $status = EnrollmentStatus::tryFrom((string) $request->query('status', ''));

        // The turmas the FILTERS leave standing, narrowed in memory from the
        // collection above rather than with a second whereHas — the answer is
        // already loaded, and a subquery would only re-derive it.
        $scopedClassIds = $visibleClasses
            ->when($class !== null, fn (EloquentCollection $classes) => $classes->where('id', $class?->getKey()))
            ->when($academicYear !== null, fn (EloquentCollection $classes) => $classes->where('academic_year_id', $academicYear?->getKey()))
            ->modelKeys();

        $organizationId = $this->currentOrganization->id();

        $students = Student::query()
            // WHO APPEARS: anybody with at least one enrolment in the turmas the
            // filters left standing. `whereHas` and not a join, so a student in
            // three of this teacher's turmas is still one row (§4).
            ->whereHas('enrollments', function (Builder $query) use ($scopedClassIds, $status): void {
                $query->whereIn('class_id', $scopedClassIds);

                if ($status !== null) {
                    $query->where('status', $status);
                }
            })
            ->when($term !== '', fn (Builder $query) => $query->where(
                fn (Builder $inner) => $this->applySearch($inner, $term, $organizationId),
            ))
            // WHAT IS SHOWN of each of them: every enrolment of theirs in a
            // turma THIS teacher teaches — not only the ones the filters
            // matched. Somebody found by filtering «7.º A» is also in «8.º B»,
            // and hiding that would make the row lie about where they are. A
            // colleague's turma is not in this whitelist at all, so it cannot
            // appear either way.
            ->with([
                'enrollments' => fn ($query) => $query->whereIn('class_id', $visibleClassIds)->orderBy('class_number'),
                'identity',
                'enrollments.schoolClass.subject',
                'enrollments.schoolClass.academicYear',
            ])
            // ORDER. The name cannot be it: display_name is encrypted at rest
            // and its blind index answers equality only, never «greater than»
            // (ADR-0004, §22.3). Sorting by it would mean decrypting the whole
            // roll before paginating it, which is exactly what paginating is
            // here to avoid. So the roll's own number orders the page — and a
            // turma filtered here then reads in the order the Relação de Turma
            // has it, which in a Portuguese school IS alphabetical. Ties, and
            // students the roll never numbered, fall back on a stable key so
            // page 2 can never repeat a row from page 1.
            //
            // AND THE TURMA DOES NOT LEAD THIS ORDER, deliberately. Ordering by
            // turma first needs ONE turma per row, and a student enrolled in two
            // of this teacher's turmas has no such thing — picking one would be
            // inventing a «turma principal» for them. It would also be
            // incoherent: min(class_id) and min(class_number) can come from
            // different enrolments, sorting somebody under 7.º A with 8.º B's
            // number. Nothing is lost by leaving it out, because whenever a
            // context IS chosen — the turma filter, or an ano with one turma in
            // it — $scopedClassIds holds a single class and this order already
            // reads exactly as «turma → class_number → id».
            ->withMin(
                ['enrollments as roll_number' => fn ($query) => $query->whereIn('class_id', $scopedClassIds)],
                'class_number',
            )
            ->orderByRaw('roll_number is null')
            ->orderBy('roll_number')
            ->orderBy('students.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $students->through(fn (Student $student): array => [
            'student_ulid' => $student->ulid,
            // Shown to the teacher of their own turma, who is authorized to know
            // it. The pseudonym is what leaves the application (§19.3).
            // `optional()` and not `?->`: the same idiom ClassController and
            // StudentProgressController already use for this exact read. A
            // Student with no identity is a real state (an import that never
            // carried a name), not an impossible one.
            'name' => optional($student->identity)->display_name ?? __('(sem identidade)'),
            'pseudonym' => $student->pseudonym_code,
            // A URL onto the guarded route, never the bytes and never the path.
            'photo_url' => $student->photoUrl(),
            'enrollments' => $student->enrollments->map(fn (Enrollment $enrollment): array => [
                'enrollment_ulid' => $enrollment->ulid,
                'class_ulid' => $enrollment->schoolClass->ulid,
                'class_label' => $enrollment->schoolClass->label,
                'subject' => $enrollment->schoolClass->subject->name,
                'academic_year' => $enrollment->schoolClass->academicYear->label,
                'class_number' => $enrollment->class_number,
                'status' => $enrollment->status->value,
                'status_label' => $enrollment->status->label(),
                'is_current' => $enrollment->status->isCurrent(),
            ])->values()->all(),
        ]);

        return Inertia::render('students/Index', [
            'students' => $students,
            'filters' => [
                'q' => $term,
                'class' => $class?->ulid,
                'year' => $academicYear?->ulid,
                'status' => $status?->value,
            ],
            'classes' => $visibleClasses->map(fn (SchoolClass $schoolClass): array => [
                'ulid' => $schoolClass->ulid,
                'label' => $schoolClass->label,
                'subject' => $schoolClass->subject->name,
                'academic_year' => $schoolClass->academicYear->label,
            ])->values()->all(),
            'academicYears' => $visibleClasses
                ->map(fn (SchoolClass $schoolClass): AcademicYear => $schoolClass->academicYear)
                ->unique('id')
                ->sortByDesc('starts_on')
                ->map(fn (AcademicYear $year): array => ['ulid' => $year->ulid, 'label' => $year->label])
                ->values()
                ->all(),
            'statuses' => array_map(
                fn (EnrollmentStatus $case): array => ['value' => $case->value, 'label' => $case->label()],
                EnrollmentStatus::cases(),
            ),
        ]);
    }

    /**
     * «?q=» — the two searches the stored shape actually supports, and no third.
     *
     * The pseudonym is plain text, so a PREFIX is honest and cheap: «ALU-7»
     * narrows to the codes beginning that way. The name is encrypted, so the
     * only thing the database can answer about it is «is it exactly this one?»,
     * through the blind index (ADR-0004, §22.3). Partial name matching would
     * need either an n-gram index or a LIKE over ciphertext — the first leaks
     * the shape of names into a new table, the second matches nothing — and
     * neither is built here. The page's own filter over the rows already loaded
     * is what covers «só me lembro de metade do nome», and it says so on screen.
     *
     * `organization_id` IS STATED HERE, on purpose. `identity` is reached from a
     * Student that is already organization-scoped, so this is a second lock on a
     * door that is shut — but StudentIdentity carries no global scope of its
     * own, and every query in this file that touches it has to read as safe on
     * its own terms.
     *
     * @param  Builder<Student>  $query
     */
    protected function applySearch(Builder $query, string $term, int $organizationId): void
    {
        $query
            ->where('pseudonym_code', 'like', self::likePrefix(Str::upper($term)))
            ->orWhereHas('identity', fn (Builder $identity) => $identity
                ->where('organization_id', $organizationId)
                ->where('display_name_index', BlindIndex::of($term)));
    }

    /**
     * A LIKE prefix whose wildcards are the query's and never the user's: a term
     * containing % or _ must match those characters, not everything.
     */
    protected static function likePrefix(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
    }

    /**
     * The «turma» filter, resolved AGAINST WHAT THE TEACHER MAY SEE rather than
     * against the database: a ulid belonging to a colleague's turma — or to
     * another school's — simply does not resolve, and the page answers as if no
     * turma had been asked for. Nothing about that class is confirmed either
     * way, not even that it exists.
     *
     * @param  EloquentCollection<int, SchoolClass>  $visibleClasses
     */
    protected function resolveClassFilter(Request $request, EloquentCollection $visibleClasses): ?SchoolClass
    {
        $ulid = (string) $request->query('class', '');

        return $ulid === '' ? null : $visibleClasses->firstWhere('ulid', $ulid);
    }

    /**
     * The «ano letivo» filter, resolved the same way and from the same place:
     * the years offered are the ones this teacher's own turmas run in, never the
     * organization's full list.
     *
     * @param  EloquentCollection<int, SchoolClass>  $visibleClasses
     */
    protected function resolveYearFilter(Request $request, EloquentCollection $visibleClasses): ?AcademicYear
    {
        $ulid = (string) $request->query('year', '');

        if ($ulid === '') {
            return null;
        }

        return $visibleClasses
            ->map(fn (SchoolClass $schoolClass): AcademicYear => $schoolClass->academicYear)
            ->firstWhere('ulid', $ulid);
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
