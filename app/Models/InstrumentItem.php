<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\InstrumentItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The one and only scoring unit (§4.2). A written question, a rubric criterion,
 * or an oral observation are all items — the UI hides the uniformity, the engine
 * always sees the same shape.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $instrument_id
 * @property int $instrument_group_id
 * @property string $code
 * @property string|null $label
 * @property int $sequence
 * @property string $points_possible
 * @property string $scoring_mode
 * @property bool $is_bonus
 * @property string|null $source_group_label
 */
#[Fillable([
    'instrument_id', 'instrument_group_id', 'code', 'label', 'sequence', 'points_possible',
    'scoring_mode', 'scale_id', 'is_bonus', 'source_group_label',
])]
class InstrumentItem extends Model
{
    /** @use HasFactory<InstrumentItemFactory> */
    use BelongsToOrganization, HasFactory, HasUlids;

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'points_possible' => 'decimal:4',
            'is_bonus' => 'boolean',
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
     * The section of the instrument this question sits in. Kept alongside
     * instrument_id rather than replacing it: every reader of an instrument's
     * items (the correction grid, the engine, the results) fetches them by
     * instrument, and going through groups would add a join to each of them for
     * no gain. The two are kept consistent by InstrumentBuilder.
     *
     * @return BelongsTo<InstrumentGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(InstrumentGroup::class, 'instrument_group_id');
    }

    /**
     * How this item's points spread across domains. An item with no allocations
     * enters no domain at all — only the instrument total. That is legitimate
     * (a "presentation" question may belong to no domain) and the engine says so
     * in its explanation.
     *
     * @return HasMany<ItemDomainAllocation, $this>
     */
    public function domainAllocations(): HasMany
    {
        return $this->hasMany(ItemDomainAllocation::class);
    }

    /**
     * Every score recorded against this question — the deciding factor in
     * whether it may still be removed or have its points_possible lowered
     * during an edit (see InstrumentBuilder::update()).
     *
     * @return HasMany<StudentItemScore, $this>
     */
    public function scores(): HasMany
    {
        return $this->hasMany(StudentItemScore::class);
    }
}
