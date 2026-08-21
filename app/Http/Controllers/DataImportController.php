<?php

namespace App\Http\Controllers;

use App\Actions\DataImports\ExecuteDataImport;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Models\DataImport;
use App\Models\DataImportStatus;
use App\Services\Audit\AuditLog;
use App\Services\Import\Backup\BuildImportPlan;
use App\Services\Import\Backup\ReadBackupUpload;
use App\Services\Import\Backup\ValidateBackupPayload;
use App\Support\Import\Backup\BackupValidationException;
use App\Support\Import\DataImportTempStorage;
use App\Support\Retention\RetentionPolicy;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The restore wizard's three steps, kept as thin as CorrectionImportController:
 * nothing here parses, classifies or writes. ReadBackupUpload reads the file,
 * ValidateBackupPayload validates and whitelists it, BuildImportPlan
 * classifies it, ExecuteDataImport persists it. This chooses what to render
 * and translates domain exceptions into a message a teacher can read (§52).
 *
 * Reachable only when the account and the current organization are both
 * operational — EnsureAccountIsOperational blocks every non-GET route here
 * during a closure window, and there is no entry for these route names in
 * its allow-list (§37-38): unlike export, import is never permitted while
 * either is winding down.
 *
 * Route-bound parameters are named $dataImport, matching {data_import} —
 * Laravel's implicit binding only normalises snake_case ↔ camelCase (the
 * same reason DataExportController's own binding is $dataExport, not
 * $export); a name it cannot correlate resolves to an empty, unsaved model
 * instead of failing loudly, which is a much harder bug to notice.
 */
class DataImportController extends Controller
{
    use RefusesDuringImpersonation;

    public function __construct(
        protected DataImportTempStorage $storage,
        protected ReadBackupUpload $reader,
        protected ValidateBackupPayload $validator,
        protected BuildImportPlan $planner,
        protected ExecuteDataImport $executor,
        protected CurrentOrganization $currentOrganization,
        protected RetentionPolicy $retentionPolicy,
        protected AuditLog $audit,
    ) {}

    public function create(): Response
    {
        Gate::authorize('create', DataImport::class);

        return Inertia::render('imports/data/Create');
    }

    /**
     * Reads and validates the file into a plan. Deliberately writes nothing
     * pedagogical: at this point the teacher has only asked "what is in
     * this backup?" (§1 of the import brief — never upload → write).
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', DataImport::class);
        $this->refuseDuringImpersonation($request);

        $data = $request->validate([
            'file' => [
                'required',
                'file',
                'max:20480',
                'extensions:zip,json',
                'mimetypes:application/zip,application/x-zip-compressed,application/json,text/plain',
            ],
        ]);

        $upload = $data['file'];
        $originalName = (string) $upload->getClientOriginalName();
        $extension = strtolower((string) $upload->getClientOriginalExtension());
        $hash = hash_file('sha256', $upload->getRealPath());

        if ($hash === false) {
            return back()->withErrors(['file' => __('Não foi possível ler o ficheiro carregado.')]);
        }

        $storedPath = $this->storage->store($upload->getRealPath(), $originalName);

        try {
            $rawJson = $extension === 'zip'
                ? $this->reader->readZip($this->storage->absolutePath($storedPath))
                : $this->reader->readJson($this->storage->absolutePath($storedPath));

            $validated = $this->validator->validate($rawJson);
        } catch (BackupValidationException $exception) {
            $this->storage->delete($storedPath);

            return back()->withErrors(['file' => $exception->getMessage()]);
        }

        $dataImport = DataImport::create([
            'status' => DataImportStatus::Validated->value,
            'original_filename' => $originalName,
            'stored_path' => $storedPath,
            'file_sha256' => $hash,
            'file_size' => $upload->getSize(),
            'source_schema_version' => $validated['canonical']['schema_version'],
            'source_app_version' => $validated['canonical']['app_version'],
            'source_generated_at' => $validated['canonical']['generated_at'],
            'source_organization' => $validated['canonical']['organization'],
            'canonical_snapshot' => $validated['canonical'],
            'requested_by' => $request->user()->getKey(),
            'expires_at' => now()->addHours($this->retentionPolicy->dataExportAvailabilityHours()),
        ]);

        $this->audit->record(
            'data_import.uploaded',
            $dataImport,
            causer: $request->user(),
            summary: __('Carregou um backup de dados para restauro.'),
            properties: ['fingerprint' => substr($hash, 0, 12), 'source_schema_version' => $dataImport->source_schema_version],
        );

        return to_route('data-imports.edit', $dataImport);
    }

    /**
     * The preview — rebuilt fresh on every visit against the current
     * database state, never trusted from an earlier render, so a class
     * created (or the backup imported) by someone else in the meantime is
     * never missed (§13, §53).
     */
    public function edit(DataImport $dataImport): Response
    {
        Gate::authorize('view', $dataImport);

        $organization = $this->currentOrganization->get();
        $plan = $dataImport->status === DataImportStatus::Validated
            ? $this->planner->build($dataImport->canonical_snapshot ?? [], $organization, [])
            : null;

        return Inertia::render('imports/data/Preview', [
            'dataImport' => [
                'ulid' => $dataImport->ulid,
                'status' => $dataImport->status->value,
                'status_label' => $dataImport->status->label(),
                'original_filename' => $dataImport->original_filename,
                'source_schema_version' => $dataImport->source_schema_version,
                'source_app_version' => $dataImport->source_app_version,
                'source_generated_at' => $dataImport->source_generated_at?->toIso8601String(),
                'source_organization' => $dataImport->source_organization,
                'summary' => $dataImport->summary,
                'failure_reason' => $dataImport->failure_reason,
            ],
            'destination' => ['name' => $organization->name, 'type' => $organization->type->value],
            'plan' => $plan,
        ]);
    }

    public function confirm(Request $request, DataImport $dataImport): RedirectResponse
    {
        Gate::authorize('confirm', $dataImport);
        $this->refuseDuringImpersonation($request);

        try {
            $imported = $this->executor->execute($dataImport, $request->user());
        } catch (Throwable $exception) {
            // The write transaction already rolled back on its own — this is
            // bookkeeping outside it, not a retry of it. The technical detail
            // stays in the log (§91: never a stack trace in the UI); the row
            // records only that it failed and who was told.
            Log::error('data_import.confirm failed', ['data_import_id' => $dataImport->getKey(), 'exception' => $exception->getMessage()]);

            $dataImport->forceFill(['status' => DataImportStatus::Failed->value, 'failure_reason' => __('A importação falhou. Tente novamente ou contacte o suporte.')])->save();

            $this->audit->record(
                'data_import.failed',
                $dataImport,
                causer: $request->user(),
                summary: __('A importação de um backup falhou.'),
            );

            return back()->withErrors(['import' => __('A importação falhou. Tente novamente ou contacte o suporte.')]);
        }

        $this->storage->delete($imported->stored_path);
        $imported->forceFill(['stored_path' => null])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Importação concluída.')]);

        return to_route('data-imports.edit', $dataImport);
    }

    public function destroy(Request $request, DataImport $dataImport): RedirectResponse
    {
        Gate::authorize('cancel', $dataImport);
        $this->refuseDuringImpersonation($request);

        $this->storage->delete($dataImport->stored_path);
        $dataImport->forceFill(['status' => DataImportStatus::Cancelled->value, 'stored_path' => null])->save();

        $this->audit->record(
            'data_import.cancelled',
            $dataImport,
            causer: $request->user(),
            summary: __('Cancelou a importação de um backup de dados.'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Importação cancelada.')]);

        return to_route('data-imports.create');
    }
}
