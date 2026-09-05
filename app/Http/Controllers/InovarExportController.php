<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\InterimAssessment;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Export\FillInovarTemplate;
use App\Services\Export\InovarExportPreviewBuilder;
use App\Services\Export\InovarExportSource;
use App\Services\Export\InovarTemplateReader;
use App\Services\Export\InterimSnapshotSource;
use App\Support\Export\InovarTemplateException;
use App\Support\Export\InovarTemplateStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Exporting a period's qualitative mentions into the grid INOVAR produced.
 *
 * Three steps and no more: upload the grid, read what would be written, and —
 * only if the teacher confirms — get the same file back with the mentions in
 * it. Nothing academic is written at any point; this reads results and fills a
 * spreadsheet.
 *
 * The grid carries names, process numbers and marks, so it lives on the private
 * disk under a random token for exactly as long as those three steps take.
 */
class InovarExportController extends Controller
{
    public function __construct(
        protected InovarTemplateReader $reader,
        protected InovarExportPreviewBuilder $previewBuilder,
        protected FillInovarTemplate $filler,
        protected InovarTemplateStorage $storage,
        protected AuditLog $audit,
    ) {}

    public function create(Request $request, SchoolClass $class, string $period): Response
    {
        Gate::authorize('view', $class);

        $selected = $this->period($class, $period);
        $interim = $this->interim($request, $class);

        return Inertia::render('exports/Inovar', [
            'schoolClass' => ['ulid' => $class->ulid, 'label' => $class->label, 'subject' => $class->subject->name],
            'period' => ['ulid' => $selected->ulid, 'label' => $selected->label],
            'interim' => $this->interimPayload($interim),
            'token' => null,
            'preview' => null,
        ]);
    }

    public function store(Request $request, SchoolClass $class, string $period): Response|RedirectResponse
    {
        Gate::authorize('update', $class);

        $selected = $this->period($class, $period);
        $interim = $this->interim($request, $class);

        $data = $request->validate([
            // The grid INOVAR exports is .xls; .xlsx is accepted because a
            // school that opened and re-saved it should not be turned away.
            'template' => ['required', 'file', 'mimes:xls,xlsx', 'max:8192'],
        ]);

        $token = $this->storage->newToken();
        $this->storage->store($token, $class->id, (string) file_get_contents($data['template']->getRealPath()));

        try {
            $template = $this->reader->read($this->storage->absolutePath($token));
        } catch (InovarTemplateException $exception) {
            $this->storage->delete($token);

            return back()->withErrors(['template' => $exception->getMessage()]);
        }

        return Inertia::render('exports/Inovar', [
            'schoolClass' => ['ulid' => $class->ulid, 'label' => $class->label, 'subject' => $class->subject->name],
            'period' => ['ulid' => $selected->ulid, 'label' => $selected->label],
            'token' => $token,
            'interim' => $this->interimPayload($interim),
            'preview' => $this->previewBuilder->build($class, $selected, $template, $this->sourceFor($interim)),
        ]);
    }

    /**
     * The teacher has read the preview and said yes.
     *
     * Everything is decided again here, from the file on disk — never from what
     * the browser sends back. The preview is what the teacher saw; it is not an
     * instruction the client gets to rewrite.
     *
     * A GET, because what comes back is a FILE. A binary response cannot travel
     * through an Inertia visit — Inertia's client requires an Inertia response
     * and simply drops anything else, which is how a button ends up appearing
     * to do nothing at all. The instrument grid and the pauta export are
     * downloaded exactly this way, and this now matches them.
     *
     * A refusal redirects back with the reason, which a plain navigation lands
     * on as the page it came from.
     */
    public function generate(Request $request, SchoolClass $class, string $period, string $token): BinaryFileResponse|RedirectResponse
    {
        Gate::authorize('update', $class);

        $selected = $this->period($class, $period);
        $interim = $this->interim($request, $class);

        if (! $this->storage->exists($token)) {
            return back()->withErrors(['template' => __('A grelha carregada já não está disponível. Carregue-a novamente.')]);
        }

        // Exists, but was it issued FOR THIS CLASS? A token alone never
        // proves that (§ see InovarTemplateStorage::belongsToClass) — a
        // stranger's leaked token that happens to still be on disk must 404
        // here, never fall through to reading and downloading their grid.
        abort_unless($this->storage->belongsToClass($token, $class->id), 404);

        try {
            $templatePath = $this->storage->absolutePath($token);
            $template = $this->reader->read($templatePath);
            $preview = $this->previewBuilder->build($class, $selected, $template, $this->sourceFor($interim));

            if ($preview['summary']['blocking_errors'] !== []) {
                return back()->withErrors(['template' => $preview['summary']['blocking_errors'][0]]);
            }

            $cells = [];

            foreach ($preview['values'] as $value) {
                if ($value['writable']) {
                    $cells[] = ['row' => $value['row'], 'column' => $value['column'], 'code' => $value['inovar_code']];
                }
            }

            // Written OUTSIDE the token folder, so the uploaded grid can be
            // deleted the moment it has been used. Both are temporary; only
            // this one still has a job to do.
            $filled = $this->filler->fill(
                $templatePath,
                $template->sheet,
                $cells,
                rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.$this->fileName($class, $selected, $templatePath),
            );

            // The grid a teacher uploaded carries names, process numbers and
            // marks. It has served its purpose here and is not kept a moment
            // longer; the filled copy goes after it is sent.
            $this->storage->delete($token);

            // Audit (§22.5): who exported what, and how much of it. Never the
            // file, never a name, never a mark.
            $this->audit->record(
                'inovar.exported',
                $class,
                summary: "Grelha INOVAR gerada para {$class->label} ({$selected->label}).",
                properties: [
                    'period_id' => $selected->id,
                    'students' => $preview['summary']['matched_students'],
                    'cells' => count($cells),
                    'warnings' => count($preview['summary']['warnings']),
                ],
            );

            return response()->download($filled, basename($filled))->deleteFileAfterSend();
        } catch (InovarTemplateException $exception) {
            return back()->withErrors(['template' => $exception->getMessage()]);
        }
    }

    /** «INOVAR_7A_Portugues_1Semestre.xls» — readable, and safe as a filename. */
    protected function fileName(SchoolClass $class, AcademicPeriod $period, string $templatePath): string
    {
        $parts = array_map(
            fn (string $part): string => Str::of($part)->ascii()->replaceMatches('/[^A-Za-z0-9]+/', '')->value(),
            [$class->label, $class->subject->name, $period->label],
        );

        $extension = pathinfo($templatePath, PATHINFO_EXTENSION) ?: 'xls';

        return 'INOVAR_'.implode('_', array_filter($parts)).'.'.$extension;
    }

    protected function period(SchoolClass $class, string $ulid): AcademicPeriod
    {
        return AcademicPeriod::where('academic_year_id', $class->academic_year_id)
            ->where('ulid', $ulid)->firstOrFail();
    }

    /**
     * The kept moment being exported, if this is that kind of export.
     *
     * Resolved from the request rather than the route so the whole flow — the
     * upload, the preview and the download — is the SAME three endpoints a
     * school already uses for the end of a period. A second set would be a
     * second thing to keep in agreement with the first (§9).
     *
     * Scoped to this class: a ULID is not a key to everything.
     */
    protected function interim(Request $request, SchoolClass $class): ?InterimAssessment
    {
        $ulid = $request->query('intercalar');

        if (! is_string($ulid) || $ulid === '') {
            return null;
        }

        return InterimAssessment::query()
            ->where('class_id', $class->id)
            ->where('ulid', $ulid)
            ->firstOrFail();
    }

    /**
     * Where the mentions come from — a stored photograph, or the period as it
     * stands. Null lets the builder use its own default.
     */
    protected function sourceFor(?InterimAssessment $interim): ?InovarExportSource
    {
        return $interim === null ? null : new InterimSnapshotSource($interim);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function interimPayload(?InterimAssessment $interim): ?array
    {
        return $interim === null ? null : [
            'ulid' => $interim->ulid,
            // The teacher's own name for the moment (§8 of the naming decision).
            'name' => $interim->name,
            'reference_date_label' => $interim->reference_date->format('d/m/Y'),
        ];
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
