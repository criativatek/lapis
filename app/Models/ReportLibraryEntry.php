<?php

namespace App\Models;

use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The pedagogical library (§16): difficulties, the strategies that answer them,
 * and the objective each strategy serves.
 *
 * THE RELATION IS THE POINT (§14). A flat list of strategies is what makes
 * reports read like a template — «diferenciação pedagógica; reforço positivo;
 * trabalho de pares» under every difficulty ever recorded. Here a strategy
 * carries `related_code`, so «guiões de planificação e revisão orientada»
 * appears because the teacher validated «planificação da escrita» and not
 * because it was next in a list.
 *
 * MIXED OWNERSHIP, SHAPED LIKE `scales`. organization_id NULL is a shared
 * system entry that every school sees; a row with one belongs to a school —
 * personal entries under Pro, an approved institutional library under
 * Institucional. The global scope is «mine OR the system's», exactly as Scale's
 * is, because that is the precedent this codebase already set for the pattern.
 *
 * IT IS NOT LEGISLATION (§17). Entries are pedagogical formulations. Nothing
 * here encodes a statute, cites an article or claims legal effect: law changes,
 * and a library of sentences that has a law baked into it is wrong the day it
 * does. Legal framing lives where it already lives — InterventionLegalFramework.
 *
 * @property int $id
 * @property string $ulid
 * @property int|null $organization_id
 * @property string $kind
 * @property string|null $code
 * @property string $label
 * @property string|null $objective
 * @property string|null $body
 * @property string|null $related_code
 * @property int|null $subject_id
 * @property array<int, string>|null $tags
 * @property int $sort_order
 * @property bool $active
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'organization_id', 'kind', 'code', 'label', 'objective', 'body',
    'related_code', 'subject_id', 'tags', 'sort_order', 'active', 'created_by',
])]
class ReportLibraryEntry extends Model
{
    use HasUlids;

    public const KIND_DIFFICULTY = 'difficulty';

    public const KIND_STRATEGY = 'strategy';

    public const KIND_OBJECTIVE = 'objective';

    public const KIND_PHRASE = 'phrase';

    protected static function booted(): void
    {
        // Visible: this organization's entries plus the shared system ones.
        // Another school's are never in scope, and — as everywhere else — a
        // request with no tenant resolved throws rather than seeing everything.
        static::addGlobalScope('organization', function (Builder $query): void {
            $tenant = app(CurrentOrganization::class);
            $column = $query->getModel()->qualifyColumn('organization_id');

            $query->where(function (Builder $query) use ($tenant, $column): void {
                $query->whereNull($column);

                if ($tenant->isResolved()) {
                    $query->orWhere($column, $tenant->id());
                }
            });
        });

        static::creating(function (self $entry): void {
            // Stamp the tenant only when organization_id was not set at all. A
            // system entry created explicitly with null (the seeder) stays null.
            if (! array_key_exists('organization_id', $entry->getAttributes())) {
                $entry->organization_id = app(CurrentOrganization::class)->id();
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
            'tags' => 'array',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function isSystem(): bool
    {
        return $this->organization_id === null;
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOfKind(Builder $query, string $kind): void
    {
        $query->where('kind', $kind)->where('active', true);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeAnsweringDifficulty(Builder $query, string $code): void
    {
        $query->where('kind', self::KIND_STRATEGY)
            ->where('related_code', $code)
            ->where('active', true);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
