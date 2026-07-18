<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\DomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stable, unversioned assessment domain (Oralidade, Leitura, …).
 *
 * The domain is the identity; only its weight is versioned, in
 * profile_version_domains. Renaming a domain fixes the label across all history
 * because the concept is unchanged — it is not a rule change and creates no version.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int|null $subject_id
 * @property int|null $parent_domain_id
 * @property string $name
 * @property string $code
 * @property int $sequence
 * @property bool $is_active
 */
#[Fillable(['subject_id', 'parent_domain_id', 'name', 'code', 'sequence', 'is_active'])]
class Domain extends Model
{
    /** @use HasFactory<DomainFactory> */
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

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
