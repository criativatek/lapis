<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Um intervalo em que a recolha esteve ligada. Os dias fora de qualquer período
 * são «sem recolha», nunca zeros. Ver `UsageCoverage`.
 *
 * @property int $id
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 */
#[Fillable(['started_at', 'ended_at', 'is_open'])]
class UsageCollectionPeriod extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
