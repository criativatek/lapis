<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\SchoolClass;
use App\Models\SheetMomentKind;
use App\Services\Audit\AuditLog;
use App\Services\Reporting\ClassDocuments\MeetingPackage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * The «Pacote de reunião» of a class: a ZIP of spreadsheets with the Pauta, the Quadro Síntese and the
 * grid of every eligible evaluation element — all of it up to the period asked for, because assessment is continuous — everything that goes into the calculation of
 * the classifications, so that a network that fails at an assessment meeting does not leave a teacher
 * without the data.
 *
 * THIN ON PURPOSE. What is in the package, and the bytes of every workbook, belong to `MeetingPackage` and to
 * the services the documents' own Excel endpoints use. This controller authorises, resolves the
 * period and the moment, sends the file and leaves ONE trail.
 *
 * AUTHORISED LIKE THE PAUTA ITSELF: whoever may see the class (`SchoolClassPolicy::view`), inside the
 * `results` module (Base); the element grids need `instruments` too, and are left out of the package without it. A colleague who does not teach it gets a 403; a class or a period of another
 * organization, or of another academic year, does not resolve. A GET, so it also works while the account
 * is read-only.
 *
 * ONE TRAIL FOR THE PACKAGE, none for its parts: the package is one act of taking a list of identifiable
 * minors out of the application, and it is recorded once, with counts and options — never a name or a
 * mark. The documents' own endpoints keep their own records; the package does not emit them.
 *
 * NOTHING STAYS ON THE SERVER. The archive is built in a temporary file and deleted as soon as it has
 * been sent.
 */
class EvaluationSheetMeetingPackageController extends Controller
{
    public function __construct(
        protected MeetingPackage $package,
        protected AuditLog $audit,
    ) {}

    public function __invoke(Request $request, SchoolClass $class, string $period): BinaryFileResponse
    {
        Gate::authorize('view', $class);

        // Only a period of THIS class's academic year: any other ulid — a period of another year or of
        // another organization — is not a valid request (404, never a file of the wrong period).
        $selected = AcademicPeriod::where('academic_year_id', $class->academic_year_id)
            ->where('ulid', $period)
            ->firstOrFail();

        $moment = SheetMomentKind::fromRequest($request->query('momento') === null ? null : (string) $request->query('momento'));

        $contents = $this->package->compose($class, $selected, $moment);
        $path = $this->package->archive($contents);

        try {
            // Counts, the documents and the options — never a student's name, a mark or a level.
            $this->audit->record(
                'evaluation_sheets.meeting_package.exported',
                $class,
                summary: "Pacote de reunião de {$class->label} exportado em ZIP.",
                properties: [
                    'format' => 'zip',
                    'academic_period_ulid' => $selected->ulid,
                    'moment' => $moment->value,
                    'students' => $contents->students,
                    'documents_included' => $contents->included,
                    'periods_included' => $contents->periodsIncluded,
                    'sheets_included' => $contents->sheetsIncluded,
                    'elements_included' => $contents->elementsIncluded,
                    'elements_outside_accumulated' => $contents->elementsOutsideAccumulated,
                    'elements_provisional' => $contents->elementsProvisional,
                    'elements_omitted' => $contents->elementsOmitted,
                    'documents_omitted' => array_map(
                        fn (array $item): array => ['document' => $item['document'], 'reason' => $item['code']],
                        $contents->omitted,
                    ),
                ],
            );
        } catch (Throwable $exception) {
            // Nothing is kept on the server, not even when the trail fails.
            @unlink($path);

            throw $exception;
        }

        $response = response()
            ->download($path, $contents->archiveName, [
                'Content-Type' => 'application/zip',
            ])
            ->deleteFileAfterSend(true);

        // `BinaryFileResponse` marks itself public; this is one person's copy of documents about
        // identifiable minors, so the header is set again, whole, after it has had its say.
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        // The name is a slug, so quoting it is exact; `download()` would leave it bare.
        $response->headers->set('Content-Disposition', "attachment; filename=\"{$contents->archiveName}\"");

        return $response;
    }
}
