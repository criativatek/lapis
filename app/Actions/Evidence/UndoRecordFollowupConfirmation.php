<?php

namespace App\Actions\Evidence;

use App\Models\RecordFollowupConfirmation;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Undoes a «Registado no Inovar» declaration made by mistake. Nothing is
 * deleted: the declaration keeps who made it and when, and gains who undid it
 * and when — the history stays. The case goes back to whatever its live count
 * says. Undoing twice is a no-op.
 */
final class UndoRecordFollowupConfirmation
{
    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(RecordFollowupConfirmation $confirmation, User $teacher): RecordFollowupConfirmation
    {
        return DB::transaction(function () use ($confirmation, $teacher): RecordFollowupConfirmation {
            // The class first, as every logbook write and ConfirmRecordFollowup
            // do — an undo and a new declaration never interleave.
            SchoolClass::query()->whereKey($confirmation->followupCase->class_id)->lockForUpdate()->firstOrFail();

            /** @var RecordFollowupConfirmation $locked */
            $locked = RecordFollowupConfirmation::query()->whereKey($confirmation->id)->lockForUpdate()->firstOrFail();

            if ($locked->undone_at !== null) {
                return $locked;
            }

            $locked->update(['undone_at' => now(), 'undone_by' => $teacher->getKey()]);

            $case = $locked->followupCase;

            $this->audit->record(
                'record_followup.unconfirmed',
                $case,
                $teacher,
                'Desfeita a marcação de registado no Inovar.',
                [
                    'class_id' => $case->class_id,
                    'enrollment_id' => $case->enrollment_id,
                    'academic_period_id' => $case->academic_period_id,
                    'category' => $case->category->value,
                    'confirmation_ulid' => $locked->ulid,
                ],
            );

            return $locked;
        });
    }
}
