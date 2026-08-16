<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A student's pedagogical record — non-identifying. This model holds NO name
 * (§11.2, §22.2); the name lives in StudentIdentity. The pseudonym_code is the
 * only student token that crosses the AI boundary (§19.3).
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property string $pseudonym_code
 * @property-read StudentIdentity|null $identity
 */
#[Fillable(['pseudonym_code'])]
class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use BelongsToOrganization, HasFactory, HasUlids;

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

    /**
     * @return HasOne<StudentIdentity, $this>
     */
    public function identity(): HasOne
    {
        return $this->hasOne(StudentIdentity::class);
    }

    /**
     * @return HasMany<Enrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * «Qual é o N.º de processo deste aluno nesta organização?» — asked of the
     * domain, answered from one place.
     *
     * The identity is already per (student, organization), so a student's record
     * in this school answers for this school and no other. Null when the roster
     * never carried one, which is a real state and not a zero.
     *
     * Callers must eager-load `identity`; this touches no database of its own.
     */
    public function processNumber(): ?string
    {
        return $this->identity?->processNumber();
    }

    /**
     * The authorized route to this student's photo, or null when there is none.
     *
     * A URL, never the image itself: the bytes stay on the private disk and
     * only StudentPhotoController serves them, after its Policy check. The
     * ?v= stamp is the identity's updated_at, so a replaced photo is not
     * served from the browser's cache under the same address.
     *
     * Callers must eager-load `identity` — this touches no database of its own.
     */
    public function photoUrl(): ?string
    {
        $identity = $this->identity;

        if ($identity === null || $identity->photo_path === null) {
            return null;
        }

        return route('students.photo', $this->ulid).'?v='.$identity->updated_at->timestamp;
    }
}
