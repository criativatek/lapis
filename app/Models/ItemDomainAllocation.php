<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one item's points split across domains — the table that satisfies scenario
 * A2 (a question shared 60/40 between two domains).
 *
 * The per-item sum of 100% is a multi-row rule, so it is validated in the
 * application, not by a CHECK.
 *
 * @property int $id
 * @property int $instrument_item_id
 * @property int $domain_id
 * @property string $allocation_percent
 */
#[Fillable(['instrument_item_id', 'domain_id', 'allocation_percent'])]
class ItemDomainAllocation extends Model
{
    protected function casts(): array
    {
        return [
            'allocation_percent' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * @return BelongsTo<InstrumentItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InstrumentItem::class, 'instrument_item_id');
    }
}
