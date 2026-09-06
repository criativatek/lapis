<?php

namespace App\Http\Controllers;

use App\Domain\Export\GeneratedExportFile;
use App\Domain\Export\InovarTemplate;
use App\Domain\Export\InovarTemplateColumn;
use App\Models\AcademicPeriod;
use App\Models\ClassificationScope;
use App\Models\SchoolClass;
use App\Models\SheetMomentKind;
use App\Models\User;
use App\Services\Assessment\CaptureEvaluationSheet;
use App\Services\Audit\AuditLog;
use App\Services\Export\DecidedLevelValues;
use App\Services\Export\FillInovarTemplate;
use App\Services\Export\InovarExportPreviewBuilder;
use App\Services\Export\InovarTemplateReader;
use App\Support\Assessment\EvaluationSheetException;
use App\Support\Export\EvaluationSheetExportStorage;
use App\Support\Export\InovarLevelOption;
use App\Support\Export\InovarTemplateException;
use App\Support\Export\InovarTemplateStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * «Preparar exportação para o Inovar», from the Pauta de Avaliação.
 *
 * IT DOES NOT EXPORT WHEN YOU PRESS IT. Upload, then READ what would be written
 * — student by student, cell by cell — and only then confirm. A grid that came
 * back subtly wrong would be uploaded to the school's own platform and nobody
 * would find out until the marks had been published; the review step is the
 * whole point, and it is why this is three requests rather than one.
 *
 * NOTHING HERE IS A SECOND EXPORTER. The template is read by
 * InovarTemplateReader, matched and mapped by InovarExportPreviewBuilder,
 * written by FillInovarTemplate and stored under a token by
 * InovarTemplateStorage — the same four collaborators `exports.inovar.*` has
 * always used, untouched. What this flow adds is what happens AFTERWARDS: the
 * file is kept rather than handed over and forgotten, and the moment it froze
 * becomes a row in the pauta's own history (§13).
 *
 * WHAT COMES BACK FROM THE BROWSER IS NEVER AN INSTRUCTION. Every confirm
 * re-reads the file on disk and rebuilds the preview from it. The two things
 * the teacher genuinely decides — whether the level goes in, and into which
 * column — arrive as a request and are checked against what the FILE says is
 * possible before either is used.
 */
class EvaluationSheetInovarExportController extends Controller
{
    /**
     * The Pauta's own scope. There is no scope selector on that screen, and the
     * grid must say what the screen said.
     */
    protected const SCOPE = ClassificationScope::Period;

    public function __construct(
        protected InovarTemplateReader $reader,
        protected InovarExportPreviewBuilder $previewBuilder,
        protected FillInovarTemplate $filler,
        protected InovarTemplateStorage $uploads,
        protected EvaluationSheetExportStorage $files,
        protected DecidedLevelValues $levels,
        protected CaptureEvaluationSheet $capture,
        protected AuditLog $audit,
    ) {}

    public function create(Request $request, SchoolClass $class, AcademicPeriod $period): Response
    {
        Gate::authorize('view', $class);
        $this->guardPeriod($class, $period);

        return Inertia::render('evaluation-sheets/InovarExport', [
            ...$this->context($class, $period, $this->moment($request)),
            'token' => null,
            'preparation' => null,
        ]);
    }

    /**
     * The teacher uploaded a grid. Read it, and show what would be written.
     */
    public function store(Request $request, SchoolClass $class, AcademicPeriod $period): Response|RedirectResponse
    {
        Gate::authorize('update', $class);
        $this->guardPeriod($class, $period);

        $data = $request->validate([
            // The grid INOVAR exports is .xls; .xlsx is accepted because a
            // school that opened and re-saved it should not be turned away.
            'template' => ['required', 'file', 'mimes:xls,xlsx', 'max:8192'],
        ], [], ['template' => 'grelha do INOVAR']);

        $token = $this->uploads->newToken();
        $this->uploads->store($token, $class->id, (string) file_get_contents($data['template']->getRealPath()));

        try {
            $template = $this->reader->read($this->uploads->absolutePath($token));
        } catch (InovarTemplateException $exception) {
            // The upload carries names, process numbers and marks. A file we
            // cannot even read has no job left to do and does not linger.
            $this->uploads->delete($token);

            return back()->withErrors(['template' => $exception->getMessage()]);
        }

        $moment = $this->moment($request);

        return Inertia::render('evaluation-sheets/InovarExport', [
            ...$this->context($class, $period, $moment),
            'token' => $token,
            'preparation' => $this->preparation($class, $period, $template, $moment),
        ]);
    }

    /**
     * The teacher read the review and said yes.
     *
     * A POST that ends in a redirect, not a download: unlike the old flow, the
     * file produced here STAYS — it belongs to a record — so what the teacher
     * needs back is the history entry, and the bytes come later through the
     * authorised download route.
     */
    public function confirm(Request $request, SchoolClass $class, AcademicPeriod $period, string $token): RedirectResponse
    {
        Gate::authorize('update', $class);
        $this->guardPeriod($class, $period);

        $data = $request->validate([
            'include_level' => ['required', 'boolean'],
            'level_column' => ['nullable', 'string', 'max:3'],
            'moment_label' => ['nullable', 'string', 'max:200'],
            // Qual dos dois momentos estruturais esta grelha congela. Sem
            // indicação, o final — que é o que uma grelha do Inovar quase
            // sempre é, e o que todas as anteriores foram.
            'moment' => ['nullable', 'string', 'in:interim,final'],
        ], [], [
            'level_column' => 'coluna do nível',
            'moment_label' => 'título do momento',
        ]);

        if (! $this->uploads->exists($token)) {
            return back()->withErrors(['template' => 'A grelha carregada já não está disponível. Carregue-a novamente.']);
        }

        // Exists, but was it issued FOR THIS CLASS? See
        // InovarTemplateStorage::belongsToClass — a leaked token that still
        // resolves to a real file must 404 here, never be read further.
        abort_unless($this->uploads->belongsToClass($token, $class->id), 404);

        try {
            $templatePath = $this->uploads->absolutePath($token);
            $template = $this->reader->read($templatePath);
        } catch (InovarTemplateException $exception) {
            return back()->withErrors(['template' => $exception->getMessage()]);
        }

        // REBUILT FROM THE FILE, never from what the browser sent back. The
        // review is what the teacher saw; it is not a payload they get to edit.
        $preview = $this->previewBuilder->build($class, $period, $template);

        if ($preview['summary']['blocking_errors'] !== []) {
            return back()->withErrors(['template' => $preview['summary']['blocking_errors'][0]]);
        }

        $includeLevel = (bool) $data['include_level'];
        $levelColumn = $includeLevel ? $this->levelColumn($template, $data['level_column'] ?? null) : null;

        $decided = $this->decidedLevels($period, $preview['students']);

        $cells = [];

        foreach ($preview['values'] as $value) {
            if ($value['writable']) {
                $cells[] = ['row' => $value['row'], 'column' => $value['column'], 'code' => (string) $value['inovar_code']];
            }
        }

        $levelCells = [];

        if ($levelColumn !== null) {
            foreach ($preview['students'] as $student) {
                $value = $student['matched'] ? ($decided[(int) $student['enrollment_id']] ?? null) : null;

                // No decision, no cell. The grid keeps whatever it had — never
                // a zero, never the proposal, never a dash (§13.3, §3.3).
                if ($value !== null) {
                    $levelCells[] = ['row' => $student['row'], 'column' => $levelColumn, 'code' => $value];
                }
            }
        }

        try {
            return $this->generate($class, $period, $template, $templatePath, $token, [...$cells, ...$levelCells], [
                'preview' => $preview,
                'decided' => $decided,
                'include_level' => $levelColumn !== null,
                'level_column' => $levelColumn,
                'moment_label' => $data['moment_label'] ?? null,
                'moment' => SheetMomentKind::fromRequest($data['moment'] ?? null),
                'cell_count' => count($cells),
                'level_cell_count' => count($levelCells),
            ]);
        } catch (InovarTemplateException $exception) {
            return back()->withErrors(['template' => $exception->getMessage()]);
        }
    }

    /**
     * Fill the grid, keep it, and record the moment it froze.
     *
     * The order matters. The file is written first so its checksum is the
     * checksum of what is really on disk; the record is created second, from
     * CaptureEvaluationSheet, so a record never exists pointing at nothing. If
     * the record cannot be created the file is removed again — an orphan on a
     * private disk is a student's marks nobody accounted for.
     *
     * @param  list<array{row: int, column: string, code: string}>  $cells
     * @param  array<string, mixed>  $context
     */
    protected function generate(
        SchoolClass $class,
        AcademicPeriod $period,
        InovarTemplate $template,
        string $templatePath,
        string $token,
        array $cells,
        array $context,
    ): RedirectResponse {
        // ASKED OF THE FILE, NOT OF ITS PATH. `InovarTemplateStorage` keeps every
        // upload under the same fixed name — `template.xls` — so reading the
        // extension off the path always answered «xls» and an .xlsx grid came
        // back named .xls while carrying genuine .xlsx bytes: a file whose name
        // lies about its format, which is exactly what INOVAR refuses to import.
        //
        // `IOFactory::identify()` is the same question FillInovarTemplate asks to
        // decide how to WRITE the file, so the name and the bytes now come from
        // one answer instead of two.
        $extension = $this->extensionFor($templatePath);
        $fileName = $this->fileName($class, $period, $extension);
        $workingCopy = rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.Str::ulid().'_'.$fileName;

        $this->filler->fill($templatePath, $template->sheet, $cells, $workingCopy);

        $contents = (string) file_get_contents($workingCopy);
        @unlink($workingCopy);

        // The grid the teacher uploaded has served its purpose and is not kept
        // a moment longer. THE TEACHER'S OWN FILE WAS NEVER TOUCHED: the writer
        // reads it and saves elsewhere, and what is deleted here is our copy.
        $this->uploads->delete($token);

        $path = $this->files->put($this->files->newFolder(), $fileName, $contents);

        /** @var array<string, mixed> $preview */
        $preview = $context['preview'];
        /** @var array<int, string> $decided */
        $decided = $context['decided'];

        /** @var SheetMomentKind $moment */
        $moment = $context['moment'] ?? SheetMomentKind::Final;

        try {
            $export = $this->capture->capture(
                $class,
                $period,
                self::SCOPE,
                $this->momentLabel($period, $context['moment_label'] === null ? null : (string) $context['moment_label'], $moment),
                Carbon::parse($this->capture->defaultEffectiveDate($period)->toDateString()),
                $this->user(),
                adapter: 'inovar',
                extraWarnings: $this->exportWarnings($preview, $decided, (bool) $context['include_level']),
                file: new GeneratedExportFile(
                    disk: EvaluationSheetExportStorage::DISK,
                    path: $path,
                    // The bytes, not the payload. `payload_hash` says the frozen
                    // document was not edited; this says the spreadsheet handed
                    // over later is the one that was generated.
                    checksum: hash('sha256', $contents),
                    extension: $extension,
                ),
                moment: $moment,
            );
        } catch (EvaluationSheetException $exception) {
            $this->files->delete($path);

            throw ValidationException::withMessages(['template' => $exception->getMessage()]);
        }

        // Audit (§22.5): who exported what, and how much of it. Never a name,
        // never a mark, never the file.
        $this->audit->record(
            'evaluation-sheet.inovar.exported',
            $export,
            summary: "Grelha INOVAR gerada a partir da Pauta de Avaliação de {$class->label}.",
            properties: [
                'period_id' => $period->id,
                'students' => $preview['summary']['matched_students'],
                'cells' => (int) $context['cell_count'],
                'level_included' => (bool) $context['include_level'],
                'level_column' => $context['level_column'],
                'level_cells' => (int) $context['level_cell_count'],
                'warnings' => (int) $export->warning_count,
            ],
        );

        return redirect()
            ->route('evaluation-sheets.history', [$class->ulid])
            ->with('success', 'Grelha INOVAR gerada e guardada no histórico da pauta.');
    }

    /**
     * WHAT WOULD BE WRITTEN, said in the vocabulary of the FILE.
     *
     * Only what travels to INOVAR: the line each student matched, the mentions
     * that will be written into it, and the level if it is included. Not the
     * analytical grid, not the percentages, and deliberately NOT the Lapispro
     * domain colours — Excel does not carry them and this screen is about the
     * spreadsheet, not about the pauta.
     *
     * @return array<string, mixed>
     */
    protected function preparation(
        SchoolClass $class,
        AcademicPeriod $period,
        InovarTemplate $template,
        SheetMomentKind $moment = SheetMomentKind::Final,
    ): array {
        $preview = $this->previewBuilder->build($class, $period, $template);
        $decided = $this->decidedLevels($period, $preview['students']);

        $candidates = array_map(fn (InovarTemplateColumn $column): array => [
            'column' => $column->column,
            'header' => $column->header,
            'samples' => $column->samples,
        ], $template->candidateColumns);

        $byRow = [];

        foreach ($preview['values'] as $value) {
            $byRow[(int) $value['row']][] = [
                'column' => $value['column'],
                'domain' => $value['domain'],
                'band' => $value['qualitative_band'],
                'code' => $value['inovar_code'],
                'writable' => $value['writable'],
                'partial' => $value['coverage_warning'],
            ];
        }

        $students = [];

        foreach ($preview['students'] as $student) {
            $level = $student['matched'] ? ($decided[(int) $student['enrollment_id']] ?? null) : null;

            $students[] = [
                'row' => $student['row'],
                'process_number' => $student['process_number'],
                'name' => $student['display_name'],
                'matched' => $student['matched'],
                'issues' => $student['issues'],
                'domains' => $byRow[(int) $student['row']] ?? [],
                // The DECISION or nothing. Never `proposed_*` (§3.3).
                'level' => $level,
            ];
        }

        return [
            'students' => $students,
            'domains' => $preview['domains'],
            'summary' => $preview['summary'],
            'source' => $preview['source'],
            'level' => [
                'candidates' => $candidates,
                // O nível pertence a uma grelha que FECHA um momento, e não a
                // uma tirada a meio do caminho. Um momento intercalar diz isso
                // por si; um momento final continua a perguntá-lo às datas do
                // período, pela mesma regra aprovada de sempre.
                'default_include' => $moment->closes($period, now()),
                // Null unless the FILE names the column. Nothing is inferred
                // from position — see InovarTemplateColumn.
                'suggested_column' => InovarLevelOption::suggestedColumn($template->candidateColumns),
                'unavailable_reason' => $candidates === []
                    ? 'Este template não tem nenhuma coluna livre para o nível. Não é um erro — é a ausência de um sítio onde escrevê-lo.'
                    : null,
            ],
            'moment' => $moment->value,
            'moment_label' => $this->momentLabel($period, null, $moment),
            'effective_at' => $this->capture->defaultEffectiveDate($period)->toDateString(),
        ];
    }

    /**
     * The column the teacher chose, checked against the ones the file offers.
     *
     * A letter the browser invented is refused rather than trusted: writing
     * into a column nobody identified is exactly the mistake this whole flow
     * exists to prevent.
     */
    protected function levelColumn(InovarTemplate $template, ?string $chosen): string
    {
        $available = array_map(fn (InovarTemplateColumn $column): string => $column->column, $template->candidateColumns);

        if ($available === []) {
            throw ValidationException::withMessages([
                'level_column' => 'Este template não tem nenhuma coluna livre para o nível.',
            ]);
        }

        $chosen = $chosen === null ? '' : strtoupper(trim($chosen));

        if (! in_array($chosen, $available, true)) {
            throw ValidationException::withMessages([
                'level_column' => 'Escolha a coluna do template onde o nível deve ser escrito.',
            ]);
        }

        return $chosen;
    }

    /**
     * The decided level of every student the grid matched.
     *
     * @param  list<array<string, mixed>>  $students
     * @return array<int, string>
     */
    protected function decidedLevels(AcademicPeriod $period, array $students): array
    {
        $enrollmentIds = [];

        foreach ($students as $student) {
            if ($student['matched'] && $student['enrollment_id'] !== null) {
                $enrollmentIds[] = (int) $student['enrollment_id'];
            }
        }

        return $this->levels->for($period, self::SCOPE, $enrollmentIds);
    }

    /**
     * What was incomplete ABOUT THIS EXPORT, in sentences a person reads months
     * later.
     *
     * PEDAGOGICAL, THEREFORE NEVER BLOCKING (§7). A missing decision or an
     * unwritten mention is information the teacher may well want to complete
     * first — and may equally have good reason to export without. What the
     * system owes them is to say which students and how many, and then do as
     * they say.
     *
     * @param  array<string, mixed>  $preview
     * @param  array<int, string>  $decided
     * @return list<string>
     */
    protected function exportWarnings(array $preview, array $decided, bool $includeLevel): array
    {
        /** @var list<string> $warnings */
        $warnings = array_values($preview['summary']['warnings']);

        if (! $includeLevel) {
            return $warnings;
        }

        $missing = [];

        foreach ($preview['students'] as $student) {
            if ($student['matched'] && ! isset($decided[(int) $student['enrollment_id']])) {
                $missing[] = (string) $student['display_name'];
            }
        }

        foreach ($missing as $name) {
            $warnings[] = "{$name}: sem classificação decidida — a coluna do nível fica vazia na grelha exportada.";
        }

        return $warnings;
    }

    /**
     * What the record is called. Built from the period's OWN configuration —
     * «Exportação INOVAR — Semestre — 1.º Semestre» — so nothing hardcodes what
     * a school calls its units of time (§6). The teacher may replace it.
     */
    protected function momentLabel(AcademicPeriod $period, ?string $given, SheetMomentKind $moment = SheetMomentKind::Final): string
    {
        $given = $given === null ? '' : trim($given);

        return $given !== ''
            ? $this->capture->labelFor($given, $period, $moment)
            : 'Exportação INOVAR — '.$this->capture->suggestedLabel($period, $moment);
    }

    /**
     * Qual dos dois momentos estruturais o professor está a exportar.
     *
     * Chega como `?momento=` porque é para lá que a Pauta aponta, e a ligação
     * carrega o separador em que o professor estava. Uma palavra que não
     * reconhecemos é o momento final, que é o que uma grelha do Inovar quase
     * sempre é.
     */
    protected function moment(Request $request): SheetMomentKind
    {
        $value = $request->query('momento');

        return SheetMomentKind::fromRequest($value === null ? null : (string) $value);
    }

    /**
     * The extension the generated grid must carry, read from the file itself.
     *
     * A grid INOVAR produced is `.xls`; a school that opened and re-saved it
     * hands over `.xlsx`. FillInovarTemplate writes back whichever it was, so
     * the name has to agree with the bytes — and the only thing that knows is
     * the file. Anything else it might be identified as is not something this
     * export accepts in the first place, so `.xls` is the honest fallback.
     */
    protected function extensionFor(string $templatePath): string
    {
        return IOFactory::identify($templatePath) === 'Xlsx' ? 'xlsx' : 'xls';
    }

    /** «INOVAR_7A_Portugues_1Semestre.xls» — readable, and safe as a filename. */
    protected function fileName(SchoolClass $class, AcademicPeriod $period, string $extension): string
    {
        $parts = array_map(
            fn (string $part): string => Str::of($part)->ascii()->replaceMatches('/[^A-Za-z0-9]+/', '')->value(),
            [$class->label, $class->subject->name, $period->label],
        );

        return 'INOVAR_'.implode('_', array_filter($parts)).'.'.$extension;
    }

    /**
     * @return array<string, mixed>
     */
    protected function context(
        SchoolClass $class,
        AcademicPeriod $period,
        SheetMomentKind $moment = SheetMomentKind::Final,
    ): array {
        return [
            'schoolClass' => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
            ],
            'period' => [
                'ulid' => $period->ulid,
                'label' => $period->label,
                'kind_label' => $period->kind->label(),
                'ends_on' => $period->ends_on->toDateString(),
                // O momento de onde o professor veio, para o ecrã o dizer e
                // para o devolver intacto ao confirmar.
                'moment' => $moment->value,
                'moment_label' => $moment->momentLabel($period),
            ],
        ];
    }

    /** A period ulid is not a key to another year's periods. */
    protected function guardPeriod(SchoolClass $class, AcademicPeriod $period): void
    {
        abort_unless((int) $period->academic_year_id === (int) $class->academic_year_id, 404);
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
