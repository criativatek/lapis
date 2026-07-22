<?php

namespace App\Http\Controllers;

use App\Models\Classification;
use App\Models\ClassificationStatus;
use App\Models\SchoolClass;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The teacher's home (§23.2): their classes, and what still needs a decision —
 * how many proposals are waiting to be confirmed and how many confirmed grades
 * are waiting to be published. It only surfaces work already created elsewhere;
 * it decides nothing.
 */
class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $teacher = $this->user();

        $classes = SchoolClass::query()
            ->whereHas('teachers', fn ($query) => $query->whereKey($teacher->getKey()))
            ->with(['subject', 'academicYear'])
            ->orderByDesc('created_at')
            ->get();

        // Live classifications per (class, status) in one grouped query — the
        // enrollment join keeps it scoped to the teacher's classes; the global
        // scope keeps it scoped to the organization.
        $counts = Classification::query()
            ->join('enrollments', 'classifications.enrollment_id', '=', 'enrollments.id')
            ->whereIn('enrollments.class_id', $classes->pluck('id'))
            ->whereNot('classifications.status', ClassificationStatus::Superseded->value)
            ->selectRaw('enrollments.class_id, classifications.status, count(*) as total')
            ->groupBy('enrollments.class_id', 'classifications.status')
            ->get()
            ->groupBy('class_id');

        $classCards = $classes->map(function (SchoolClass $class) use ($counts) {
            // Keyed by the enum's string value — status is cast to an enum, so it
            // cannot be a pluck() array key directly.
            $byStatus = $counts->get($class->id, collect())
                ->mapWithKeys(fn ($row) => [$row->status->value => (int) $row->total]);

            return [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'academic_year' => $class->academicYear->label,
                'has_profile' => $class->assessment_profile_version_id !== null,
                'pending_confirmation' => (int) $byStatus->get(ClassificationStatus::Proposed->value, 0),
                'pending_publication' => (int) $byStatus->get(ClassificationStatus::Confirmed->value, 0),
            ];
        });

        return Inertia::render('Dashboard', [
            'teacherName' => $teacher->name,
            'classes' => $classCards,
            'totals' => [
                'classes' => $classCards->count(),
                'pending_confirmation' => $classCards->sum('pending_confirmation'),
                'pending_publication' => $classCards->sum('pending_publication'),
            ],
        ]);
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
