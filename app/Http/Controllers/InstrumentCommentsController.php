<?php

namespace App\Http\Controllers;

use App\Models\Instrument;
use App\Models\InstrumentStatus;
use App\Models\InstrumentStudentComment;
use App\Services\Assessment\Analysis\BuildResultsAnalysis;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The «Comentários» tab next to the correction grid: the teacher's private
 * notes on an element of assessment. Today the general observation (the same
 * `results_analysis_notes` row Resultados used to edit, saved through
 * `instruments.results.note`) and one note per question or criterion
 * (`instrument_items.criterion_note`, saved through `instruments.items.notes`).
 *
 * Reads only. The «Por aluno» block lives here too — the private comment on a
 * student in this element, saved through `instruments.students.comment` — and is
 * never mixed into the per-question notes, which are about the question and
 * never about a student.
 */
class InstrumentCommentsController extends Controller
{
    public function __construct(protected BuildResultsAnalysis $results) {}

    public function show(Instrument $instrument): Response|RedirectResponse
    {
        Gate::authorize('view', $instrument->schoolClass);

        if ($instrument->status === InstrumentStatus::Draft) {
            return to_route('instruments.edit', $instrument->ulid);
        }

        $instrument->load(['items', 'groups', 'schoolClass', 'academicPeriod']);

        $comments = InstrumentStudentComment::query()
            ->where('instrument_id', $instrument->getKey())
            ->get()
            ->keyBy('enrollment_id');

        $enrollments = $instrument->schoolClass->enrollments()
            ->with('student.identity')
            ->inClassOrder()
            ->get();

        $cancelled = $instrument->status === InstrumentStatus::Cancelled;

        return Inertia::render('instruments/Comments', [
            'instrument' => [
                'ulid' => $instrument->ulid,
                'title' => $instrument->title,
                'status' => $instrument->status->value,
                'status_label' => $instrument->status->label(),
                'applied_on' => $instrument->applied_on->toDateString(),
                'class_label' => $instrument->schoolClass->label,
                'class_ulid' => $instrument->schoolClass->ulid,
                'period' => $instrument->academicPeriod->label,
            ],
            // The same lock-versioned payload Resultados read, so the optimistic
            // lock lives in one place.
            'note' => $this->results->notePayload($instrument),
            'can_edit' => Gate::allows('update', $instrument->schoolClass),
            'items' => $instrument->items->sortBy('sequence')->values()->map(fn ($item) => [
                'id' => $item->id,
                'code' => $item->code,
                'label' => $item->label,
                'points_possible' => $item->points_possible === null ? null : (float) $item->points_possible,
                'is_bonus' => $item->is_bonus,
                'criterion_note' => $item->criterion_note,
            ])->all(),
            // Todos os alunos da grelha, pela ordem da turma: a página separa os
            // que já têm comentário dos que se podem escolher para acrescentar.
            'students' => $enrollments->map(fn ($enrollment) => [
                'enrollment_ulid' => $enrollment->ulid,
                'class_number' => $enrollment->class_number,
                'name' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)',
                'comment' => isset($comments[$enrollment->id])
                    ? ['body' => $comments[$enrollment->id]->body, 'lock_version' => $comments[$enrollment->id]->lock_version]
                    : null,
            ])->all(),
            'links' => [
                'note' => route('instruments.results.note', $instrument->ulid),
                'item_notes' => route('instruments.items.notes', $instrument->ulid),
                'grid' => route('instruments.show', $instrument->ulid),
                'results' => route('instruments.results', $instrument->ulid),
            ],
            'cancelled' => $cancelled,
        ]);
    }
}
