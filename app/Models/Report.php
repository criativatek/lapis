<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Hashing\CanonicalPayload;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A report the teacher is writing, or has finished (§53).
 *
 * NOT A DOWNLOAD. It is opened, edited, finalized, exported, listed, duplicated
 * and used as the starting point for the next one. The file is what comes out
 * at the end; this is the thing.
 *
 * TWO LIVES, ONE ROW. While `status` is draft, the content lives in
 * `sections` and regenerates from whatever the canonical read models say now.
 * At finalization the whole thing — text, structure, figures, letterhead,
 * temporal scope, provenance — is copied into `document` and the row stops
 * listening to the world. A grade corrected in May does not rewrite a report
 * finished in February (§37, §68). The model enforces that below rather than
 * merely documenting it.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property ReportType $type
 * @property ReportStatus $status
 * @property string $title
 * @property ReportTone $tone
 * @property int|null $class_id
 * @property int|null $enrollment_id
 * @property int|null $academic_year_id
 * @property int|null $academic_period_id
 * @property int|null $interim_assessment_id
 * @property ReportScopeKind $scope_kind
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property string $scope_label
 * @property array<string, mixed>|null $options
 * @property array<string, mixed>|null $teacher_input
 * @property int $teacher_input_version
 * @property int|null $based_on_report_id
 * @property string|null $template_key
 * @property array<string, mixed>|null $template_snapshot
 * @property array<string, mixed>|null $document
 * @property int|null $document_version
 * @property string|null $document_hash
 * @property Carbon|null $finalized_at
 * @property int|null $finalized_by
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * Declared because the foreign keys really are nullable and the relation
 * accessors would otherwise be inferred non-null: a records report has no
 * class, a class report has no enrollment, and a draft has no finalizer.
 * @property-read SchoolClass|null $schoolClass
 * @property-read Enrollment|null $enrollment
 * @property-read AcademicYear|null $academicYear
 * @property-read AcademicPeriod|null $academicPeriod
 * @property-read InterimAssessment|null $interimAssessment
 * @property-read self|null $basedOn
 * @property-read User|null $finalizer
 */
#[Fillable([
    'type', 'status', 'title', 'tone',
    'class_id', 'enrollment_id', 'academic_year_id', 'academic_period_id', 'interim_assessment_id',
    'scope_kind', 'starts_on', 'ends_on', 'scope_label',
    'options', 'teacher_input', 'teacher_input_version',
    'based_on_report_id', 'template_key', 'template_snapshot',
    'document', 'document_version', 'document_hash', 'finalized_at', 'finalized_by',
    'created_by',
])]
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use BelongsToOrganization, HasFactory, HasUlids;

    /**
     * The shape the current writer produces for `document`. Only ever grows.
     *
     * A version-2 reader still has to read a version-1 document. That is the
     * whole reason the number exists: what a report recorded is what it
     * observed, and a later idea about what reports ought to record never
     * reaches backwards into one already signed.
     */
    public const CURRENT_DOCUMENT_VERSION = 1;

    /** The shape of `teacher_input`. Same contract, different document. */
    public const CURRENT_TEACHER_INPUT_VERSION = 1;

    /**
     * What may still change once the report is finalized.
     *
     * NOTHING THAT IS IN THE DOCUMENT. A title is on the envelope — it appears
     * in the listing, not inside the frozen text — so correcting a typo in it
     * changes nothing anybody printed. Everything else is the document.
     */
    public const EDITABLE_AFTER_FINALIZING = ['title', 'updated_at'];

    protected static function booted(): void
    {
        // FINALIZATION IS ENFORCED, NOT TRUSTED (§37). A finalized report whose
        // content could still be edited is not finalized; it is a draft with a
        // badge. The one legitimate way to change a finished report is to
        // derive a new one from it (§32).
        static::updating(function (self $report): void {
            $wasFinalized = $report->getOriginal('status') === ReportStatus::Finalized->value
                || $report->getOriginal('status') === ReportStatus::Finalized;

            if (! $wasFinalized) {
                return;
            }

            $frozen = array_diff(array_keys($report->getDirty()), self::EDITABLE_AFTER_FINALIZING);

            if ($frozen !== []) {
                throw new \LogicException(
                    'Um relatório finalizado não se reescreve ('
                    .implode(', ', $frozen).'). Crie um novo a partir dele.',
                );
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
            'type' => ReportType::class,
            'status' => ReportStatus::class,
            'tone' => ReportTone::class,
            'scope_kind' => ReportScopeKind::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'options' => 'array',
            'teacher_input' => 'array',
            'teacher_input_version' => 'integer',
            'template_snapshot' => 'array',
            'document' => 'array',
            'document_version' => 'integer',
            'finalized_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status->isDraft();
    }

    public function isFinalized(): bool
    {
        return $this->status->isFinalized();
    }

    /** Whether the frozen document still matches the hash written with it. */
    public function isIntact(): bool
    {
        return $this->document !== null
            && $this->document_hash === self::hashFor($this->document);
    }

    /**
     * Sobre a forma canónica — ver a nota em InterimAssessment::hashFor() e
     * App\Support\Hashing\CanonicalPayload. Um hash sobre a ordem das chaves
     * não sobrevive a uma ida e volta por uma coluna JSON do MySQL.
     *
     * @param  array<string, mixed>  $document
     */
    public static function hashFor(array $document): string
    {
        return CanonicalPayload::hash($document);
    }

    /**
     * One value out of the teacher's characterization, without every caller
     * having to guard the nulls (§8).
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return data_get($this->teacher_input, $key, $default);
    }

    /**
     * One creation-time option — which sections, which filters, whether
     * students may be named.
     */
    public function option(string $key, mixed $default = null): mixed
    {
        return data_get($this->options, $key, $default);
    }

    /**
     * WHETHER THIS DOCUMENT CARRIES THE SCHOOL'S LOGO (§50).
     *
     * FALSE UNLESS THE TEACHER SAID OTHERWISE, exactly like `name_students`. A
     * school uploads a logo so its own screens and its own letterhead can use
     * it; that is not a decision that every relatório de turma, individual
     * report and logbook leaving the building is a branded institutional
     * document. Where the option was never touched, the letterhead is the
     * school's name and address — which is what identifies it — and no space is
     * reserved for an image that is not there.
     *
     * IT IS ANSWERED THE SAME WAY BEFORE AND AFTER SIGNATURE. The option is a
     * creation-time choice the teacher may revise while the report is a draft,
     * and `options` is not in EDITABLE_AFTER_FINALIZING — so finalizing freezes
     * the answer along with everything else (§39).
     *
     * THE ONE PLACE THAT DECIDES, for the preview, the PDF and the .docx alike.
     * Three renderings that each worked it out for themselves would eventually
     * disagree, and a document that carries a logo in Word and not in PDF is
     * worse than one that carries it nowhere.
     *
     * ---
     *
     * READING A DOCUMENT SIGNED BEFORE THIS OPTION EXISTED. Every report
     * finalized before `show_logo` was introduced has no such key at all, and
     * answering those with the new default would silently take the logo off
     * documents a school has already sent — changing how a signed document
     * looks, which is exactly what §39 forbids. So absence is not read as
     * false: it is read as a question the document itself already answered.
     *
     * A finalized report froze its letterhead at signature. If that frozen
     * identity carries a `logo_path`, this document printed a logo when it was
     * signed and goes on printing it. If it does not, it never did.
     *
     * THREE THINGS THIS DELIBERATELY DOES NOT DO. It never reads the school's
     * CURRENT identity — only the copy frozen inside this document —, so
     * uploading or removing a logo today cannot change a document signed last
     * February. It never writes: no option is backfilled, no migration runs,
     * `document` and `document_hash` are not touched, and the answer is
     * recomputed from frozen bytes on every read. And it never reaches a draft,
     * which has frozen nothing and therefore has nothing to be compatible with.
     *
     * AN EXPLICIT ANSWER ALWAYS WINS, in both directions. A report that carries
     * `show_logo => false` and a frozen logo prints no logo: somebody chose
     * that, and this fallback is for documents where nobody was ever asked.
     */
    public function showsLogo(): bool
    {
        $options = $this->options ?? [];

        // array_key_exists, not option(): `data_get` cannot tell a stored
        // `false` from a key that was never written, and the whole rule turns
        // on that distinction.
        if (array_key_exists('show_logo', $options)) {
            return (bool) $options['show_logo'];
        }

        if (! $this->isFinalized()) {
            return false;
        }

        return $this->frozeALogo();
    }

    /**
     * Whether this document's own frozen letterhead carries a logo.
     *
     * Read from `document.identity.logo_path` and from nowhere else — the
     * school's live identity is a different fact about a different moment.
     */
    public function frozeALogo(): bool
    {
        $path = data_get($this->document, 'identity.logo_path');

        return is_string($path) && trim($path) !== '';
    }

    /**
     * WHETHER THIS REPORT MAY NAME A STUDENT (§28, §57).
     *
     * False unless the teacher explicitly said otherwise, and irrelevant for an
     * individual report, which is about one named person by definition.
     */
    public function namesStudents(): bool
    {
        if ($this->type === ReportType::Student) {
            return true;
        }

        return (bool) $this->option('name_students', false);
    }

    /**
     * @return HasMany<ReportSection, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(ReportSection::class)->orderBy('position');
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * @return BelongsTo<AcademicPeriod, $this>
     */
    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class);
    }

    /**
     * @return BelongsTo<InterimAssessment, $this>
     */
    public function interimAssessment(): BelongsTo
    {
        return $this->belongsTo(InterimAssessment::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'based_on_report_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function derivedReports(): HasMany
    {
        return $this->hasMany(self::class, 'based_on_report_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeDrafts(Builder $query): void
    {
        $query->where('status', ReportStatus::Draft);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeFinalized(Builder $query): void
    {
        $query->where('status', ReportStatus::Finalized);
    }
}
