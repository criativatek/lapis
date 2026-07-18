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
}
