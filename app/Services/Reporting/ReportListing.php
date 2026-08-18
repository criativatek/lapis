<?php

namespace App\Services\Reporting;

use App\Models\Report;
use App\Models\ReportStatus;
use App\Models\ReportType;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Relatórios listing (§54).
 *
 * WHAT A TEACHER SEES is what ReportPolicy would let them open: their own
 * reports, plus reports about classes they teach. Written once here as a query
 * so that the listing and the policy cannot drift into disagreeing — a row a
 * teacher can see and then cannot open is a bug report waiting to happen.
 *
 * The school-wide report is deliberately absent from the general case: it
 * aggregates across colleagues and belongs to whoever owns the organization, so
 * it appears only for them.
 *
 * Eager-loads everything the rows display. A listing that lazily resolves a
 * class, a subject, an author and a parent report per row is thirty queries for
 * ten reports (§62).
 */
class ReportListing
{
    /**
     * @param  array{type?: string|null, status?: string|null, class_id?: int|null, enrollment_id?: int|null, academic_period_id?: int|null}  $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function for(User $user, array $filters = [], int $limit = 100): array
    {
        $query = $this->visibleTo($user)
            ->with([
                'schoolClass.subject',
                'enrollment.student.identity',
                'academicPeriod',
                'academicYear',
                'author',
                'basedOn',
            ]);

        $this->applyFilters($query, $filters);

        $total = (clone $query)->toBase()->getCountForPagination();

        $reports = $query
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        return [
            'rows' => array_values($reports->map(fn (Report $report) => $this->row($report))->all()),
            'total' => $total,
        ];
    }

    /**
     * @return Builder<Report>
     */
    public function visibleTo(User $user): Builder
    {
        $ownsOrganization = $this->ownsCurrentOrganization($user);

        return Report::query()->where(function (Builder $query) use ($user, $ownsOrganization): void {
            $query->where('created_by', $user->getKey());

            // Reports about a class this user teaches, whoever wrote them.
            $query->orWhereIn('class_id', SchoolClass::query()
                ->whereHas('teachers', fn ($teachers) => $teachers->whereKey($user->getKey()))
                ->select('id'));

            if ($ownsOrganization) {
                $query->orWhere('type', ReportType::School);
            }
        });
    }

    /**
     * @param  Builder<Report>  $query
     * @param  array<string, mixed>  $filters
     */
    protected function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when(($filters['type'] ?? null) !== null, fn (Builder $query) => $query->where('type', $filters['type']))
            ->when(($filters['status'] ?? null) !== null, fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(($filters['class_id'] ?? null) !== null, fn (Builder $query) => $query->where('class_id', $filters['class_id']))
            ->when(($filters['enrollment_id'] ?? null) !== null, fn (Builder $query) => $query->where('enrollment_id', $filters['enrollment_id']))
            ->when(($filters['academic_period_id'] ?? null) !== null, fn (Builder $query) => $query->where('academic_period_id', $filters['academic_period_id']));
    }

    /**
     * @return array<string, mixed>
     */
    public function row(Report $report): array
    {
        return [
            'ulid' => $report->ulid,
            'title' => $report->title,
            'type' => $report->type->value,
            'type_label' => $report->type->label(),
            'status' => $report->status->value,
            'status_label' => $report->status->label(),
            // What it is ABOUT, in one string — a class, a student, the school.
            'subject_label' => $this->subjectLabel($report),
            // The temporal scope, always stated (§29). Never left for the
            // reader to infer from a period name that may not be there.
            'scope_label' => $report->scope_label,
            'author' => $report->author?->name,
            'created_at' => $report->created_at->toIso8601String(),
            'updated_at' => $report->updated_at->toIso8601String(),
            'finalized_at' => $report->finalized_at?->toIso8601String(),
            // §54: a report that started from another one says so, and links
            // back to it. The original is never touched (§32).
            'based_on' => $report->basedOn === null ? null : [
                'ulid' => $report->basedOn->ulid,
                'title' => $report->basedOn->title,
            ],
        ];
    }

    /**
     * WHAT the report is about, in one string.
     *
     * The academic year is deliberately not repeated here: it is already in
     * `scope_label`, which every report has and which is the column that
     * answers «quando» (§29). This column answers «sobre quê».
     */
    protected function subjectLabel(Report $report): string
    {
        return match ($report->type) {
            ReportType::SchoolClass => $this->classLabel($report),
            ReportType::Student => $this->studentLabel($report).' · '.$this->classLabel($report),
            ReportType::Records => $report->class_id === null
                ? 'Todas as turmas'
                : $this->classLabel($report),
            ReportType::School => 'Toda a escola',
        };
    }

    protected function classLabel(Report $report): string
    {
        $class = $report->schoolClass;

        if ($class === null) {
            return '—';
        }

        return $class->label.' · '.$class->subject->name;
    }

    protected function studentLabel(Report $report): string
    {
        return optional($report->enrollment?->student->identity)->display_name ?? '(sem identidade)';
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function statusOptions(): array
    {
        return array_map(
            fn (ReportStatus $status) => ['value' => $status->value, 'label' => $status->label()],
            ReportStatus::cases(),
        );
    }

    protected function ownsCurrentOrganization(User $user): bool
    {
        $tenant = app(CurrentOrganization::class);

        return $tenant->isResolved() && (int) $tenant->get()->owner_id === (int) $user->getKey();
    }
}
