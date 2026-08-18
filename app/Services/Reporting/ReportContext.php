<?php

namespace App\Services\Reporting;

use App\Domain\Reporting\SectionCatalogue;
use App\Domain\Reporting\SectionKey;
use App\Models\Report;
use App\Models\ReportTone;

/**
 * Everything a composer is allowed to look at, gathered once.
 *
 * A COMPOSER MAY NOT QUERY. It receives this and nothing else — the facts a
 * source already read, the teacher's own statements, the school's letterhead,
 * and what the plan permits. That is what keeps the module's central rule
 * enforceable rather than merely stated: a section cannot compute a result
 * because it cannot reach the database to compute one from (§1, §62).
 *
 * Built once per generation and reused across every section, so a class report
 * with fourteen sections still costs one pass over the read models.
 */
readonly class ReportContext
{
    /**
     * @param  array<string, mixed>  $facts  What a ReportSource read.
     * @param  array<string, mixed>  $identity  The school's letterhead (§39).
     */
    public function __construct(
        public Report $report,
        public array $facts,
        public array $identity,
        public ReportCapabilities $capabilities,
    ) {}

    /**
     * One fact by dotted path — `summary.success.rate`, `domains.0.label`.
     */
    public function fact(string $path, mixed $default = null): mixed
    {
        return data_get($this->facts, $path, $default);
    }

    /** Whether the source found anything to report on at all. */
    public function hasFacts(): bool
    {
        return (bool) ($this->facts['available'] ?? false);
    }

    /**
     * Whether the facts came from a kept photograph rather than live data.
     * Sections that would otherwise say «no momento atual» must not (§30).
     */
    public function readsSnapshot(): bool
    {
        return ($this->facts['origin'] ?? null) === 'interim_snapshot';
    }

    /**
     * One of the teacher's own statements (§8). Absent means unanswered, which
     * is never the same as a neutral answer.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->report->input($key, $default);
    }

    public function tone(): ReportTone
    {
        return $this->report->tone;
    }

    /** Whether this report may put an individual student's name in it (§28). */
    public function namesStudents(): bool
    {
        return $this->report->namesStudents();
    }

    /**
     * Whether a section is permitted here at all — the plan's answer, asked on
     * the server (§4).
     */
    public function allows(SectionKey $key): bool
    {
        $definition = SectionCatalogue::find($this->report->type, $key);

        return $definition !== null && $this->capabilities->allowsSection($definition);
    }

    /**
     * The temporal scope, in the words the report was created with (§29). Every
     * section that states a figure may reach for this rather than reconstruct
     * a period name.
     */
    public function scopeLabel(): string
    {
        return $this->report->scope_label;
    }
}
