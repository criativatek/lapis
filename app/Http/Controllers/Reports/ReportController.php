<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\ReportType;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Reporting\ReportCapabilities;
use App\Services\Reporting\ReportListing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Relatórios — the module's own home (§53, §54).
 *
 * A report here is an object with a life: created, edited, finalized, exported,
 * reused. The pauta — the sheet of decided grades — lives beside it under
 * `pautas.*` and is a different artifact: a table of classifications, not a
 * document with sections and an author.
 */
class ReportController extends Controller
{
    public function __construct(
        protected ReportListing $listing,
        protected ReportCapabilities $capabilities,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Report::class);

        $filters = [
            'type' => $request->query('type'),
            'status' => $request->query('status'),
            'class_id' => $request->query('class_id') !== null ? (int) $request->query('class_id') : null,
        ];

        $listing = $this->listing->for($this->user(), $filters);

        return Inertia::render('reports/Index', [
            'reports' => $listing['rows'],
            'total' => $listing['total'],
            'filters' => $filters,
            // Which kinds this school's plan allows, so the "Novo relatório"
            // menu offers exactly what will actually be accepted (§4).
            'availableTypes' => array_map(
                fn (ReportType $type) => ['value' => $type->value, 'label' => $type->label()],
                $this->capabilities->availableTypes(),
            ),
            'statuses' => ReportListing::statusOptions(),
            'classes' => $this->teachingClasses(),
        ]);
    }

    /**
     * The classes this teacher can build a report about — also the filter's
     * options, so the two can never offer different sets.
     *
     * @return list<array<string, mixed>>
     */
    protected function teachingClasses(): array
    {
        return array_values(SchoolClass::query()
            ->whereHas('teachers', fn ($query) => $query->whereKey($this->user()->getKey()))
            ->with(['subject', 'academicYear'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (SchoolClass $class) => [
                'id' => $class->id,
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'academic_year' => $class->academicYear->label,
            ])
            ->all());
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
