<?php

namespace App\Http\Controllers;

use App\Actions\Lessons\MaterializeLessonsForRange;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Requests\Lessons\RecurringLessonSlotRequest;
use App\Models\Lesson;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Gate;

class LessonScheduleController extends Controller implements HasMiddleware
{
    use RefusesDuringImpersonation;

    public function __construct(protected MaterializeLessonsForRange $materializeLessonsForRange) {}

    /**
     * @return list<string>
     */
    public static function middleware(): array
    {
        return ['module:lessons'];
    }

    public function store(RecurringLessonSlotRequest $request): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        RecurringLessonSlot::create($request->validated());

        return back();
    }

    public function update(
        RecurringLessonSlotRequest $request,
        RecurringLessonSlot $recurringLessonSlot,
    ): RedirectResponse {
        $this->refuseDuringImpersonation($request);

        $recurringLessonSlot->update($request->safe()->except('class_id'));

        return back();
    }

    public function destroy(Request $request, RecurringLessonSlot $recurringLessonSlot): RedirectResponse
    {
        Gate::authorize('delete', $recurringLessonSlot);
        $this->refuseDuringImpersonation($request);

        $recurringLessonSlot->delete();

        return back();
    }

    public function materialize(Request $request, SchoolClass $class): RedirectResponse
    {
        Gate::authorize('create', [Lesson::class, $class]);
        $this->refuseDuringImpersonation($request);

        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $this->materializeLessonsForRange->execute(
            $class,
            CarbonImmutable::parse($validated['from'], 'Europe/Lisbon'),
            CarbonImmutable::parse($validated['to'], 'Europe/Lisbon'),
            $this->user($request),
        );

        return back();
    }

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
