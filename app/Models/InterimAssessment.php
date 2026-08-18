<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * «Avaliação intercalar»: how a class stood on a date, kept.
 *
 * The payload holds literal copies — values, band identities, the words that
 * were on screen at the time — and never live foreign keys into results that
 * can still move. That is the whole point: rename a domain, correct a score or
 * reconfigure a scale next term and the photograph keeps showing what was true
 * when it was taken (§9, §15).
 *
 * WRITTEN ONCE AND NEVER UPDATED. There is deliberately no `updated_at`, the
 * model refuses to save over itself, and the payload carries a hash so that a
 * rewrite around the model would still be detectable rather than merely
 * forbidden (§10).
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $class_id
 * @property int $academic_period_id
 * @property int $created_by
 * @property string $name
 * @property Carbon $reference_date
 * @property string|null $note
 * @property int $snapshot_version
 * @property array<string, mixed> $snapshot
 * @property string $snapshot_hash
 * @property Carbon $created_at
 */
#[Fillable([
    'class_id', 'academic_period_id', 'created_by', 'name', 'reference_date',
    'note', 'snapshot_version', 'snapshot', 'snapshot_hash', 'created_at',
])]
class InterimAssessment extends Model
{
    use BelongsToOrganization, HasUlids;

    /**
     * The shape the current writer produces. Only ever grows.
     *
     * v1 → v2: the summary gained a `success` block. A v1 document does not
     * have one and is never given one — it is a photograph, and what it did not
     * record it never observed. Readers show «—» for it rather than a zero,
     * because «não foi registado» and «ninguém passou» are different sentences.
     *
     * v2 → v3: `success` changed what it COUNTS. Up to v2 it counted the
     * mentions the calculated averages landed on; from v3 it counts the
     * classifications the teacher actually assigned, which is what an official
     * pass rate means. The key is the same and the question is not, so the
     * version — not the presence of the key — is what tells a reader whether
     * two figures may be placed side by side. v2 documents keep their own
     * meaning and are never rewritten.
     *
     * v3 → v4: the document gained `assigned_distribution` — how many students
     * were GIVEN each level, beside the existing `distribution` of where their
     * averages landed. Additive: a v3 photograph has no such block and never
     * grows one, and a reader shows «não registado» rather than a row of zeros.
     */
    public const CURRENT_VERSION = 4;

    public $timestamps = false;

    /**
     * The only things that may change after the photograph is taken.
     *
     * A NAME IS NOT HISTORY. What must never move is what the class looked
     * like — the snapshot, the date it was read at, and the period it belongs
     * to. What a school calls that moment is a label on the outside of the
     * envelope, and correcting a typo in it changes nothing anybody compared or
     * exported.
     */
    public const EDITABLE_AFTER_CAPTURE = ['name', 'note'];

    protected static function booted(): void
    {
        // IMMUTABILITY, ENFORCED RATHER THAN DOCUMENTED. A snapshot that could
        // be updated is not a snapshot: the moment somebody "fixes" one, every
        // comparison and every export ever taken from it silently changes
        // meaning. Correcting the CONTENT means creating a new one beside it.
        static::updating(function (self $interim): void {
            $frozen = array_diff(array_keys($interim->getDirty()), self::EDITABLE_AFTER_CAPTURE);

            if ($frozen !== []) {
                throw new \LogicException(
                    'Uma avaliação intercalar é uma fotografia e o seu conteúdo não se reescreve ('
                    .implode(', ', $frozen).'). Crie uma nova.',
                );
            }
        });
    }

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
            'reference_date' => 'date',
            'created_at' => 'datetime',
            'snapshot' => 'array',
            'snapshot_version' => 'integer',
        ];
    }

    /** Whether the payload still matches the hash written with it. */
    public function isIntact(): bool
    {
        return $this->snapshot_hash === self::hashFor($this->snapshot);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function hashFor(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * @return BelongsTo<AcademicPeriod, $this>
     */
    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
