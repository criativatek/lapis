<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A structural section of an instrument — "Grupo I", "Oralidade", "Parte B".
 *
 * A group is HOW the test is laid out; a domain is WHAT a question assesses.
 * They are independent: a question sits in exactly one group and may still
 * split its points across several domains (ItemDomainAllocation). Treating a
 * domain as a section is what produced two Q2 in one instrument and a unique
 * constraint violation the teacher saw as a 500.
 *
 * `label` is NULL for the implicit group every instrument has. A simple
 * instrument keeps exactly one, and the teacher never sees it — the structure
 * only becomes visible once they create groups of their own.
 *
 * The label is deliberately not unique and is not identity: two groups may
 * share a name, renaming one changes nothing else, and questions are addressed
 * through the group's id/ulid. source_group_label on the item is a different
 * thing entirely — where an imported row came from, never structure.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $instrument_id
 * @property string|null $label
 * @property int $sequence
 */
#[Fillable(['instrument_id', 'label', 'sequence'])]
class InstrumentGroup extends Model
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
            'sequence' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Instrument, $this>
     */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }

    /**
     * @return HasMany<InstrumentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InstrumentItem::class)->orderBy('sequence');
    }

    /**
     * Whether this is the unnamed group every instrument starts with. The UI
     * hides it, so a teacher who never asked for structure never sees any.
     */
    public function isImplicit(): bool
    {
        return $this->label === null;
    }
}
