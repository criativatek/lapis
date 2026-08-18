<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One section of a draft report (§44).
 *
 * TWO TEXTS AT ONCE, AND THAT IS THE DESIGN. `generated_body` is what the
 * system wrote from the data; `body` is what will actually be printed. They
 * start equal. The moment the teacher touches the section they diverge, `edited`
 * becomes true, and both are kept — so «restaurar texto automático» has
 * something to restore and regenerating one section never silently destroys a
 * paragraph somebody wrote.
 *
 * `key` is stable and `heading` is not: regenerating, reordering and excluding
 * all find a section by what it IS, never by what it is currently called.
 *
 * Rows exist only while the report is a draft. A finalized report carries its
 * sections inside `reports.document`, frozen — these rows are then history of
 * how it was assembled, not the document itself.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $report_id
 * @property string $key
 * @property string $heading
 * @property int $position
 * @property bool $included
 * @property string|null $body
 * @property string|null $generated_body
 * @property bool $edited
 * @property list<string>|null $sources
 * @property array<string, mixed>|null $data
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'report_id', 'key', 'heading', 'position', 'included',
    'body', 'generated_body', 'edited', 'sources', 'data',
])]
class ReportSection extends Model
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
            'position' => 'integer',
            'included' => 'boolean',
            'edited' => 'boolean',
            'sources' => 'array',
            'data' => 'array',
        ];
    }

    /**
     * Whether the automatic text can be put back — i.e. whether there is one
     * and it differs from what is there now.
     */
    public function canRestore(): bool
    {
        return $this->generated_body !== null && $this->body !== $this->generated_body;
    }

    /**
     * Whether this section has anything to print. An empty section is dropped
     * from the document rather than printed as a heading over a blank (§41).
     */
    public function hasContent(): bool
    {
        return trim((string) $this->body) !== '' || ($this->data ?? []) !== [];
    }

    /**
     * @return BelongsTo<Report, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}
