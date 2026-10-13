<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One follow-up key — a student, in one class (one subject), one category,
 * one assessment period. Holds WHEN the threshold was crossed (the alert) and
 * the teacher's «Registado no Inovar» declarations; never the count, which is
 * always read live from the logbook (RecordFollowupCounter).
 *
 * `alerted_at` null means the row exists only because the teacher confirmed a
 * case that already had three or more occurrences before alerts existed — it
 * was never notified, on purpose.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $class_id
 * @property int $enrollment_id
 * @property int $academic_period_id
 * @property RecordFollowupCategory $category
 * @property Carbon|null $alerted_at
 * @property int|null $alerted_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['class_id', 'enrollment_id', 'academic_period_id', 'category', 'alerted_at', 'alerted_count'])]
class RecordFollowupCase extends Model
{
    use BelongsToOrganization, HasUlids;

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    protected function casts(): array
    {
        return [
            'category' => RecordFollowupCategory::class,
            'alerted_at' => 'datetime',
            'alerted_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * @return BelongsTo<AcademicPeriod, $this>
     */
    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class);
    }

    /**
     * Every declaration, undone ones included — the history.
     *
     * @return HasMany<RecordFollowupConfirmation, $this>
     */
    public function confirmations(): HasMany
    {
        return $this->hasMany(RecordFollowupConfirmation::class)->orderBy('confirmed_at')->orderBy('id');
    }

    /**
     * The one declaration in force, if any. ConfirmRecordFollowup keeps it at
     * most one by locking this row.
     *
     * @return HasOne<RecordFollowupConfirmation, $this>
     */
    public function activeConfirmation(): HasOne
    {
        return $this->hasOne(RecordFollowupConfirmation::class)->whereNull('undone_at')->latest('confirmed_at')->latest('id');
    }
}
