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
 * A reusable arrangement of a report (§0).
 *
 * IT CONFIGURES THE ENGINE; IT IS NOT A SECOND ENGINE. A template says which
 * sections a report of a given type has, in what order, in what tone, with what
 * options. Everything that turns those choices into sentences is the composer
 * layer that already exists, unchanged.
 *
 * WHAT IT MAY NEVER HOLD, and the reason the distinction is worth a paragraph:
 * a template is reused across classes and across years. The moment it contains
 * an average, a classification, a student's name or a generated sentence about
 * a real case, it stops being reusable and starts being a leak — the same
 * paragraph about one class printed into another's report. `settings` is
 * structure and preferences, and SaveReportAsTemplate is the one writer that
 * has to be careful about it.
 *
 * A STARTING POINT, NOT A LIVE LINK (§13, §14). A report reads its template
 * once, at creation, and keeps a snapshot. Editing the template afterwards
 * changes nothing that already exists.
 *
 * VISIBILITY, shaped like `scales`: organization_id NULL is a shared system row
 * every school sees; a row with one belongs to a school. The per-user narrowing
 * that personal templates need cannot live in a global scope — a scope reading
 * auth() breaks in jobs, commands and tests — so it lives in visibleTo(), the
 * same way ReportListing already does it.
 *
 * @property int $id
 * @property string $ulid
 * @property int|null $organization_id
 * @property int|null $user_id
 * @property ReportTemplateKind $kind
 * @property string|null $key
 * @property ReportType $report_type
 * @property string $name
 * @property string|null $description
 * @property array<string, mixed> $settings
 * @property bool $is_default
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $author
 * @property-read Organization|null $organization
 */
#[Fillable([
    'organization_id', 'user_id', 'kind', 'key', 'report_type',
    'name', 'description', 'settings', 'is_default', 'is_active',
])]
class ReportTemplate extends Model
{
    use HasUlids;

    protected static function booted(): void
    {
        // Visible: this organization's templates plus the shared system ones.
        // Another school's are never in scope, and — as everywhere else — a
        // request with no tenant resolved sees only the system rows rather than
        // everything.
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

        static::creating(function (self $template): void {
            // Stamp the tenant only when organization_id was not set at all. A
            // system template created explicitly with null (the seeder) stays
            // null.
            if (! array_key_exists('organization_id', $template->getAttributes())) {
                $template->organization_id = app(CurrentOrganization::class)->id();
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
            'kind' => ReportTemplateKind::class,
            'report_type' => ReportType::class,
            'settings' => 'array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The templates a given user may choose from.
     *
     * System templates, the school's institutional ones, and their own personal
     * ones — never a colleague's. Written once here so that the picker and the
     * policy cannot drift into disagreeing (§38).
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(function (Builder $query) use ($user): void {
            $query->where('kind', ReportTemplateKind::System)
                ->orWhere('kind', ReportTemplateKind::Institutional)
                ->orWhere(function (Builder $query) use ($user): void {
                    $query->where('kind', ReportTemplateKind::Personal)
                        ->where('user_id', $user->getKey());
                });
        });
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function isSystem(): bool
    {
        return $this->kind->isSystem();
    }

    /**
     * The sections this template arranges, as stored.
     *
     * @return list<array<string, mixed>>
     */
    public function sections(): array
    {
        $sections = $this->settings['sections'] ?? [];

        return is_array($sections) ? array_values(array_filter($sections, 'is_array')) : [];
    }

    public function tone(): ?ReportTone
    {
        $tone = $this->settings['tone'] ?? null;

        return is_string($tone) ? ReportTone::tryFrom($tone) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        $options = $this->settings['options'] ?? [];

        return is_array($options) ? $options : [];
    }

    /**
     * WHAT A REPORT KEEPS ABOUT THE TEMPLATE IT STARTED FROM (§15, §30).
     *
     * Enough to reprint and to explain: which template, what it was called,
     * whose it was, and exactly what it said at that moment. Taken once, at
     * creation — never read back, because the template may have changed.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'ulid' => $this->ulid,
            'key' => $this->key,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'name' => $this->name,
            'settings' => $this->settings,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
