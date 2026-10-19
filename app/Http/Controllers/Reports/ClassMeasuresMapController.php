<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Documents\Pdf\DompdfPrinter;
use App\Services\Reporting\ClassDocuments\ClassMeasuresMap;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Mapa de Medidas Educativas: a class document made when it is asked for.
 *
 * NOT A REPORT. It has no row in `reports`, no author, no draft and no
 * finalisation, and nothing it produces is kept: the preview and the PDF are
 * both built on the spot from Estratégias e Medidas as they stand, from the
 * same structure (ClassMeasuresMap), so what is on the screen is what is
 * printed. A teacher who changes a measure and asks again gets the new map;
 * there is no stale copy anywhere to contradict it.
 *
 * AUTHORIZED LIKE THE CLASS ITSELF. Whoever teaches the class may open it
 * (SchoolClassPolicy::view); a colleague who does not gets a 403, and a class
 * of another organization does not resolve at all because the route binding
 * runs through the tenant scope.
 *
 * THE FILTERS ARE PRESENTATION. `implementadas` and `a_implementar` only choose
 * which columns are drawn; they never reach a query that could widen what a
 * teacher may see.
 */
class ClassMeasuresMapController extends Controller
{
    public function __construct(
        protected ClassMeasuresMap $map,
        protected AuditLog $audit,
    ) {}

    /** The picker: no class chosen yet, so no document. */
    public function index(): Response
    {
        return Inertia::render('reports/class-documents/MeasuresMap', [
            'schoolClass' => null,
            'document' => null,
            'filters' => ['implemented' => true, 'planned' => true],
            'classes' => $this->teachingClasses(),
        ]);
    }

    public function show(Request $request, SchoolClass $class): Response
    {
        Gate::authorize('view', $class);

        $filters = $this->filters($request);

        return Inertia::render('reports/class-documents/MeasuresMap', [
            'schoolClass' => $this->classPayload($class->loadMissing(['subject', 'academicYear'])),
            'document' => $this->map->build($class, $filters['implemented'], $filters['planned']),
            'filters' => $filters,
            'classes' => $this->teachingClasses(),
        ]);
    }

    /**
     * The same document as a PDF, streamed straight back and never stored: it
     * is one person's copy of a list about identifiable minors.
     */
    public function pdf(Request $request, SchoolClass $class, DompdfPrinter $printer): HttpResponse
    {
        Gate::authorize('view', $class);

        $filters = $this->filters($request);
        $document = $this->map->build($class, $filters['implemented'], $filters['planned']);

        $bytes = $printer->print(
            View::make('reports.class-measures-map', ['document' => $document])->render(),
        );

        // Counts and the class label only — never a name. Exporting is the
        // moment a list of students with support measures leaves the
        // application, which is what the trail is for.
        $this->audit->record(
            'reports.measures_map.exported',
            $class,
            summary: "Mapa de Medidas Educativas de {$class->label} exportado em PDF.",
            properties: [
                'format' => 'pdf',
                'columns' => array_keys(array_filter($document['columns'])),
                'students_with_measures' => $document['summary']['students_with_measures'],
                'implemented' => $document['summary']['implemented'],
                'planned' => $document['summary']['planned'],
            ],
        );

        $name = str("mapa-de-medidas-educativas {$class->label} {$class->subject->name}")
            ->slug()
            ->limit(80, '')
            ->value();

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$name}.pdf\"",
            // Never cached by a proxy: this is one person's copy of one
            // document about identifiable minors.
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /**
     * Which columns to draw. Both default to shown; asking for neither is a
     * forged URL (the screen never sends it) and shows both rather than an
     * empty sheet.
     *
     * @return array{implemented: bool, planned: bool}
     */
    protected function filters(Request $request): array
    {
        $accepted = Rule::in(['0', '1', 'true', 'false']);

        $request->validate([
            'implementadas' => ['nullable', $accepted],
            'a_implementar' => ['nullable', $accepted],
        ]);

        $implemented = $this->flag($request->query('implementadas'));
        $planned = $this->flag($request->query('a_implementar'));

        if (! $implemented && ! $planned) {
            return ['implemented' => true, 'planned' => true];
        }

        return ['implemented' => $implemented, 'planned' => $planned];
    }

    protected function flag(mixed $value): bool
    {
        return $value === null || filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array{ulid: string, label: string, subject: string, academic_year: string}
     */
    protected function classPayload(SchoolClass $class): array
    {
        return [
            'ulid' => $class->ulid,
            'label' => $class->label,
            'subject' => $class->subject->name,
            'academic_year' => $class->academicYear->label,
        ];
    }

    /**
     * The classes this teacher teaches, newest school year first — the only
     * ones the picker offers, so it never lists a class the policy would then
     * refuse. No numeric id leaves here.
     *
     * @return list<array{ulid: string, label: string, subject: string, academic_year: string}>
     */
    protected function teachingClasses(): array
    {
        /** @var User $user */
        $user = request()->user();

        return array_values(SchoolClass::query()
            ->whereHas('teachers', fn ($query) => $query->whereKey($user->getKey()))
            ->with(['subject', 'academicYear'])
            ->get()
            ->sortBy([
                fn (SchoolClass $left, SchoolClass $right): int => $right->academicYear->starts_on <=> $left->academicYear->starts_on,
                fn (SchoolClass $left, SchoolClass $right): int => strcmp($left->label, $right->label),
            ])
            ->map(fn (SchoolClass $class): array => $this->classPayload($class))
            ->all());
    }
}
