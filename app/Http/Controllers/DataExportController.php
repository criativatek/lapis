<?php

namespace App\Http\Controllers;

use App\Actions\DataExports\GenerateDataExport;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Models\DataExport;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Exportar os meus dados" (Fatia 4, §20). Available on every plan — this is
 * portability, not a paid feature (§52). Content is always the requester's
 * OWN accessible scope; see `GenerateDataExport`'s docblock for why an
 * institutional owner does not get more pedagogical data through this door.
 *
 * GENERATING AND DOWNLOADING STAY UNGATED, THE HISTORY DOES NOT. Matriz
 * Mestre §7 marks «Exportação dos próprios dados» and «Exportação RGPD» for
 * every plan and «Histórico de backups do utilizador» for Pro and
 * Institucional; §20 puts the RGPD export among the platform's own
 * properties, which is why no capability may ever stand between a teacher
 * and a copy of their data. So the two rows are honoured separately: a Base
 * organization always sees what it can still download, and the LIST OF PAST
 * EXPORTS — including the expired ones, which are a record of backups taken
 * rather than a way to obtain data — is the part `data_backup_restore` gates.
 * Restoring one of them is gated on the route (routes/web.php, data-imports).
 *
 * `download()` streams binary bytes, which an Inertia XHR response cannot
 * represent — so this page never triggers it via `router.post()`. Generating
 * (`store`) redirects back to `index`, which lists ready exports as plain
 * `<a href>` links, the same "native navigation, not an Inertia visit"
 * pattern the existing report PDF/DOCX export already uses.
 */
class DataExportController extends Controller
{
    use RefusesDuringImpersonation;

    public function __construct(
        protected CurrentOrganization $currentOrganization,
        protected GenerateDataExport $generateDataExport,
        protected Entitlements $entitlements,
    ) {}

    public function index(): Response
    {
        $organization = $this->currentOrganization->get();
        $user = $this->user();

        $keepsHistory = $this->entitlements->allows('data_backup_restore');

        return Inertia::render('data-exports/Index', [
            'exports' => DataExport::query()
                ->where('requested_by', $user->getKey())
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(fn (DataExport $export): array => [
                    'ulid' => $export->ulid,
                    'created_at' => $export->created_at?->toDateTimeString(),
                    'expires_at' => $export->expires_at?->toDateTimeString(),
                    'ready' => $export->isReady() && ! $export->isExpired(),
                ])
                // Without the backup capability this is not a history, it is
                // the download link for what was just asked for: everything
                // still live, and nothing about exports that are already gone.
                ->filter(fn (array $export): bool => $keepsHistory || $export['ready'])
                ->values(),
            'keepsHistory' => $keepsHistory,
            'organizationName' => $organization->name,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        $organization = $this->currentOrganization->get();
        $user = $this->user();

        try {
            $export = $this->generateDataExport->generate($organization, $user);
        } catch (\Throwable) {
            return back()->withErrors(['export' => __('Não foi possível gerar a exportação. Tente novamente.')]);
        }

        if (! $export->isReady()) {
            return back()->withErrors(['export' => __('Não foi possível gerar a exportação. Tente novamente.')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Exportação gerada. O transferível fica disponível durante 24 horas.')]);

        return to_route('data-exports.index');
    }

    public function download(DataExport $dataExport): StreamedResponse
    {
        Gate::authorize('download', $dataExport);

        if ($dataExport->downloaded_at === null) {
            $dataExport->update(['downloaded_at' => now()]);
        }

        $organizationSlug = str($dataExport->organization->name)->slug()->limit(40, '')->value();
        $filename = 'Lapispro-exportacao-'.$organizationSlug.'-'.$dataExport->created_at->toDateString().'.zip';

        return Storage::disk('local')->download($dataExport->disk_path, $filename);
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
