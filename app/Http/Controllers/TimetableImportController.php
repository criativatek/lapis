<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Requests\Lessons\TimetableImportConfirmRequest;
use App\Models\AcademicYear;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Import\Timetable\BuildTimetableImportPreview;
use App\Services\Import\Timetable\DetectSlotConflicts;
use App\Services\Import\Timetable\PdfTextExtractor;
use App\Services\Import\Timetable\TimetableParser;
use App\Services\Import\Timetable\TimetablePdfException;
use App\Support\Retention\ResolveSelectedAcademicYear;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Importing a teacher's own timetable export into recurring lesson slots.
 *
 * Three steps and no wizard: choose a file, review what was read, confirm. The
 * middle step is where all the value is — nothing is created until the teacher
 * has seen, turma by turma, exactly which blocks would be added, which already
 * exist, which clash, and which could not be recognised at all.
 *
 * THIS IS A SHORTCUT TO THE MANUAL SCREEN, NOT A REPLACEMENT FOR IT. Every slot
 * it creates is an ordinary RecurringLessonSlot, indistinguishable from one
 * typed by hand, editable and deletable in exactly the same place. It creates no
 * Lesson: materialising the weeks stays where it already was, behind the
 * teacher's own «Preparar aulas» buttons. And it keeps nothing — the uploaded
 * PDF is read in memory and forgotten, never stored, never a source of truth
 * after the fact.
 */
class TimetableImportController extends Controller implements HasMiddleware
{
    use RefusesDuringImpersonation;

    public function __construct(
        protected PdfTextExtractor $extractor,
        protected TimetableParser $parser,
        protected BuildTimetableImportPreview $previewBuilder,
        protected DetectSlotConflicts $conflicts,
        protected ResolveSelectedAcademicYear $resolveAcademicYear,
    ) {}

    /**
     * @return list<string>
     */
    public static function middleware(): array
    {
        return ['module:lessons'];
    }

    public function create(Request $request): Response
    {
        Gate::authorize('viewAny', SchoolClass::class);

        return Inertia::render('timetable-imports/Create', [
            'academicYear' => $this->selectedAcademicYear($request)?->label,
        ]);
    }

    public function store(Request $request): Response|RedirectResponse
    {
        Gate::authorize('viewAny', SchoolClass::class);
        $this->refuseDuringImpersonation($request);

        $data = $request->validate([
            // `mimetypes` sniffs the file's real content rather than believing
            // the browser's own claim about it; `extensions` still checks the
            // name, so a renamed spreadsheet fails on both counts. 8 MB is the
            // same ceiling the other imports in this application use, and far
            // past any real timetable.
            'timetable' => ['required', 'file', 'max:8192', 'extensions:pdf', 'mimetypes:application/pdf'],
        ]);

        $academicYear = $this->selectedAcademicYear($request);

        if ($academicYear === null) {
            return back()->withErrors(['timetable' => __('Selecione um ano letivo antes de importar um horário.')]);
        }

        $contents = file_get_contents($data['timetable']->getRealPath());

        if ($contents === false) {
            return back()->withErrors(['timetable' => __('Não foi possível ler o ficheiro carregado.')]);
        }

        try {
            // Parsed straight from memory: the upload never becomes a file of
            // ours, so there is no temporary path to clean up and none to leak.
            $timetable = $this->parser->parse($this->extractor->extract($contents));
        } catch (TimetablePdfException $exception) {
            // Every one of these messages is written for the teacher — a scan
            // with no text layer, an encrypted file, a PDF that is simply not a
            // timetable. Expected outcomes, not stack traces.
            return back()->withErrors(['timetable' => $exception->getMessage()]);
        }

        $classes = $this->teacherClasses($request, $academicYear);

        return Inertia::render('timetable-imports/Preview', [
            ...$this->previewBuilder->build($timetable, $classes),
            'academicYear' => $academicYear->label,
            'fileAcademicYear' => $timetable->academicYearLabel,
            // Never blocks the import — a teacher may legitimately be setting up
            // next year from this year's file — but it is stated plainly, and it
            // never changes the selected year behind their back.
            'yearMismatch' => $timetable->academicYearNormalised !== null
                && $timetable->academicYearNormalised !== $academicYear->label,
            'teacherName' => $timetable->teacherName,
        ]);
    }

    public function confirm(TimetableImportConfirmRequest $request): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        $validated = $request->validated();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = is_array($validated['rows'] ?? null) ? $validated['rows'] : [];
        $startsOn = $validated['starts_on'] ?? null;
        $endsOn = $validated['ends_on'] ?? null;

        $created = 0;
        $existed = 0;
        $conflicted = 0;
        $touched = [];

        // One transaction for the batch: an unexpected failure halfway through
        // must not leave a half-imported timetable behind. Duplicates and
        // conflicts are NOT failures — they are skipped and counted, because a
        // batch of twenty new blocks and two already-present ones should still
        // add the twenty.
        DB::transaction(function () use ($rows, $startsOn, $endsOn, &$created, &$existed, &$conflicted, &$touched): void {
            $existingByClass = [];

            foreach ($rows as $row) {
                if (! (bool) ($row['include'] ?? false)) {
                    continue;
                }

                $classId = (int) $row['class_id'];

                if (! array_key_exists($classId, $existingByClass)) {
                    // Re-resolved through the tenant-scoped model, and re-read
                    // from the database rather than from the preview: what the
                    // schedule looks like NOW is the only thing worth checking
                    // against.
                    $schoolClass = SchoolClass::query()->find($classId);

                    if ($schoolClass === null) {
                        continue;
                    }

                    $existingByClass[$classId] = $schoolClass->recurringLessonSlots()->get();
                }

                $existing = $existingByClass[$classId];
                $dayOfWeek = (int) $row['day_of_week'];
                $startsAt = (string) $row['starts_at'];
                $endsAt = (string) $row['ends_at'];

                $status = $this->conflicts->status($existing, $dayOfWeek, $startsAt, $endsAt);

                if ($status === DetectSlotConflicts::STATUS_EXISTS) {
                    $existed++;

                    continue;
                }

                if ($status === DetectSlotConflicts::STATUS_CONFLICT) {
                    $conflicted++;

                    continue;
                }

                $slot = RecurringLessonSlot::create([
                    'class_id' => $classId,
                    'day_of_week' => $dayOfWeek,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    // One window for the whole batch, written identically onto
                    // every slot — or null on every slot, exactly as the manual
                    // form leaves them when the teacher fills nothing in.
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                ]);

                // Kept in step within the batch itself, so two rows of the same
                // payload can never both be created for the same block.
                $existing->push($slot);

                $created++;
                $touched[$classId] = true;
            }
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $this->summary(
                $created,
                count($touched),
                $existed,
                $conflicted,
                (int) ($validated['unassociated_count'] ?? 0),
            ),
        ]);

        return to_route('classes.index');
    }

    /**
     * What actually happened, counted rather than summarised away.
     *
     * «Importação concluída» on its own would hide the two blocks that clashed
     * and the four the file used for something that is not a turma, which are
     * precisely the things the teacher still has to do something about.
     */
    protected function summary(int $created, int $classCount, int $existed, int $conflicted, int $unassociated): string
    {
        $parts = [__('Horário importado: :aulas aula(s) adicionada(s) em :turmas turma(s).', [
            'aulas' => $created,
            'turmas' => $classCount,
        ])];

        if ($existed > 0) {
            $parts[] = __(':total entrada(s) já existiam.', ['total' => $existed]);
        }

        if ($conflicted > 0) {
            $parts[] = __(':total entrada(s) ficaram por criar por se sobreporem a aulas já marcadas.', ['total' => $conflicted]);
        }

        if ($unassociated > 0) {
            $parts[] = __(':total entrada(s) não foram associadas.', ['total' => $unassociated]);
        }

        return implode(' ', $parts);
    }

    /**
     * The turmas this import may ever touch: the current organization's (the
     * model's own global scope), taught by this teacher (class_teachers), in the
     * year currently selected. Nothing else is loaded, so nothing else can be
     * matched, offered in a dropdown, or written to.
     *
     * @return Collection<int, SchoolClass>
     */
    protected function teacherClasses(Request $request, AcademicYear $academicYear): Collection
    {
        return SchoolClass::query()
            ->where('academic_year_id', $academicYear->getKey())
            ->whereHas('teachers', fn ($query) => $query->whereKey($this->user($request)->getKey()))
            ->with(['subject', 'recurringLessonSlots'])
            ->orderBy('label')
            ->get();
    }

    protected function selectedAcademicYear(Request $request): ?AcademicYear
    {
        $years = AcademicYear::query()->orderByDesc('starts_on')->get();
        $selectedId = $request->session()->get('academic_year_id');

        return $this->resolveAcademicYear->for($years, is_int($selectedId) ? $selectedId : null);
    }

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
