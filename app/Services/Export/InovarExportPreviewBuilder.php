<?php

namespace App\Services\Export;

use App\Domain\Export\InovarTemplate;
use App\Models\AcademicPeriod;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Services\Assessment\BuildResultsProgression;
use App\Services\Assessment\ClassResultsCalculator;
use App\Services\Assessment\CoverageExplanation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What would be written, and everything standing in the way of writing it.
 *
 * NOTHING IS CALCULATED HERE. The mentions come from BuildResultsProgression —
 * the same read model the Quadro Síntese shows — so what a school uploads to
 * INOVAR is what the teacher already read on screen, and there is no second
 * opinion about anybody's marks.
 *
 * The teacher sees this before anything is filled in, which is the point: a
 * grid that came back subtly wrong would be uploaded, and nobody would find out
 * until the marks were.
 */
class InovarExportPreviewBuilder
{
    public function __construct(
        protected BuildResultsProgression $progression,
        protected InovarCodeResolver $codes,
        protected ClassResultsCalculator $calculator,
        protected CoverageExplanation $coverage,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(
        SchoolClass $class,
        AcademicPeriod $period,
        InovarTemplate $template,
        ?InovarExportSource $source = null,
    ): array {
        // The period as it stands, unless a kept moment was handed over. The
        // grid, the matching, the filling and the fidelity checks are identical
        // either way — only where the mentions come from differs (§9, §10).
        $source ??= new CurrentPeriodResultsSource(
            $class, $period, $this->progression, $this->calculator, $this->coverage, $this->codes,
        );

        $enrollments = $this->enrollments($class);

        $domains = $this->domainRows($class, $template);
        $students = $this->studentRows($enrollments, $template);
        $values = $this->valueRows($source, $students, $domains);

        $blocking = $this->blockingErrors($students, $domains, $source, $this->withoutProcessNumber($enrollments));
        $warnings = $this->warnings($students, $values);

        return [
            'students' => $students,
            'domains' => $domains,
            'values' => $values,
            'source' => [
                'label' => $source->label(),
                'reference_label' => $source->referenceLabel(),
            ],
            'summary' => [
                'matched_students' => count(array_filter($students, fn (array $row): bool => $row['matched'])),
                'unmatched_students' => count(array_filter($students, fn (array $row): bool => ! $row['matched'])),
                'mapped_domains' => count(array_filter($domains, fn (array $row): bool => $row['mapped'])),
                'unmapped_domains' => count(array_filter($domains, fn (array $row): bool => ! $row['mapped'])),
                'ready_cells' => count(array_filter($values, fn (array $row): bool => $row['writable'])),
                'partial_coverage' => $this->partialCoverage($values),
                'warnings' => $warnings,
                'blocking_errors' => $blocking,
            ],
        ];
    }

    /**
     * The grid's columns matched to the profile's domains, by their names.
     *
     * EXACT, after squishing and lowercasing — never fuzzy. Both sides are
     * written by the same school about the same subject, and a column filled
     * with another domain's marks is the failure this whole flow exists to
     * avoid.
     *
     * @return list<array<string, mixed>>
     */
    protected function domainRows(SchoolClass $class, InovarTemplate $template): array
    {
        $version = $class->profileVersion;
        $domainIds = $version === null ? collect() : $version->domains()->pluck('domain_id');

        $byName = [];

        foreach (Domain::whereIn('id', $domainIds)->orderBy('name')->get() as $domain) {
            $byName[$this->normalize($domain->name)] = $domain;
        }

        $rows = [];

        foreach ($template->domainColumns as $column => $header) {
            $domain = $byName[$this->normalize($header)] ?? null;

            $rows[] = [
                'inovar_column' => $column,
                'inovar_header' => $header,
                'lapis_domain' => $domain?->name,
                'lapis_domain_id' => $domain?->id,
                'mapped' => $domain !== null,
                'issues' => $domain === null
                    ? ["A coluna «{$header}» não corresponde a nenhum domínio do perfil desta turma."]
                    : [],
            ];
        }

        return $rows;
    }

    /**
     * The grid's lines matched to this class's students, by N.º DE PROCESSO and
     * by nothing else.
     *
     * Never the name: two students share one often enough, and a mark written
     * against the wrong person is not a mistake anybody catches by reading. The
     * name travels only so the teacher recognises the line.
     *
     * @param  Collection<int, Enrollment>  $enrollments
     * @return list<array<string, mixed>>
     */
    protected function studentRows(Collection $enrollments, InovarTemplate $template): array
    {
        $byProcessNumber = [];

        foreach ($enrollments as $enrollment) {
            $number = $enrollment->student->processNumber();

            if ($number === null || trim($number) === '') {
                continue;
            }

            $byProcessNumber[$this->normalizeNumber($number)][] = $enrollment;
        }

        $seenInTemplate = [];
        $rows = [];

        foreach ($template->students as $line) {
            $key = $line->processNumber === null ? null : $this->normalizeNumber($line->processNumber);
            $issues = [];

            if ($key === null) {
                $issues[] = 'Esta linha da grelha não tem N.º de processo.';
            } elseif (isset($seenInTemplate[$key])) {
                $issues[] = "O N.º de processo {$line->processNumber} aparece mais do que uma vez nesta grelha.";
            }

            $candidates = $key === null ? [] : ($byProcessNumber[$key] ?? []);

            if ($key !== null && count($candidates) > 1) {
                $issues[] = "Há mais do que um aluno desta turma com o N.º de processo {$line->processNumber}.";
            }

            if ($key !== null && $candidates === []) {
                $issues[] = 'Não há nesta turma nenhum aluno com este N.º de processo.';
            }

            if ($key !== null) {
                $seenInTemplate[$key] = true;
            }

            $matched = $issues === [] && count($candidates) === 1;

            $rows[] = [
                'row' => $line->row,
                'process_number' => $line->processNumber,
                'display_name' => $line->name,
                'enrollment_id' => $matched ? $candidates[0]->id : null,
                'matched' => $matched,
                'issues' => $issues,
            ];
        }

        return $rows;
    }

    /**
     * The students of this class the export cannot address at all.
     *
     * A N.º de processo is needed the day somebody exports to INOVAR, and not
     * before — a class typed in by hand has none, and everything else about it
     * works. So this is reported here, where it matters, and nowhere else.
     *
     * @param  Collection<int, Enrollment>  $enrollments
     * @return list<string>
     */
    protected function withoutProcessNumber(Collection $enrollments): array
    {
        $names = [];

        foreach ($enrollments as $enrollment) {
            $number = $enrollment->student->processNumber();

            if ($number === null || trim($number) === '') {
                $names[] = optional($enrollment->student->identity)->display_name ?? '(sem identidade)';
            }
        }

        return $names;
    }

    /**
     * One row per (matched student × mapped domain): the band, its INOVAR code,
     * and whether the cell can be written.
     *
     * @param  list<array<string, mixed>>  $students
     * @param  list<array<string, mixed>>  $domains
     * @return list<array<string, mixed>>
     */
    protected function valueRows(InovarExportSource $source, array $students, array $domains): array
    {
        // Whatever the source is, it answers the same question: for this
        // enrolment and this domain, which mention, which code, and why was it
        // partial. The builder never asks how it knows.
        $cells = $source->cells();

        $rows = [];

        foreach ($students as $student) {
            if (! $student['matched']) {
                continue;
            }

            foreach ($domains as $domain) {
                if (! $domain['mapped']) {
                    continue;
                }

                $cell = $cells[$student['enrollment_id']][$domain['lapis_domain_id']] ?? null;
                $code = $cell['inovar_code'] ?? null;

                $rows[] = [
                    'row' => $student['row'],
                    'column' => $domain['inovar_column'],
                    'student' => $student['display_name'],
                    'domain' => $domain['lapis_domain'],
                    'qualitative_band' => $cell['band_label'] ?? null,
                    'inovar_code' => $code,
                    'coverage_warning' => (bool) ($cell['coverage_warning'] ?? false),
                    // The elements the engine itself named as the reason: their
                    // instrument, its date and the state that was RECORDED
                    // against them. Never a state inferred from a missing score
                    // — a cell nobody has graded yet says nothing about whether
                    // anybody was there.
                    'coverage_elements' => $cell['coverage_elements'] ?? [],
                    // No mention is no mark. The cell is left exactly as the
                    // grid had it — never an F, never a zero (§10).
                    'writable' => $code !== null,
                ];
            }
        }

        return $rows;
    }

    // Reading the mentions and resolving a band's code moved to
    // CurrentPeriodResultsSource, which is now one of two places that can
    // answer that question. The builder no longer knows which it is talking to.

    /**
     * @param  list<array<string, mixed>>  $students
     * @param  list<array<string, mixed>>  $domains
     * @param  list<string>  $withoutNumber
     * @return list<string>
     */
    protected function blockingErrors(array $students, array $domains, InovarExportSource $source, array $withoutNumber): array
    {
        $errors = [];

        if ($withoutNumber !== []) {
            $errors[] = 'Existem alunos sem N.º de processo. Complete esta informação para poder exportar para o INOVAR.';
        }

        foreach ($students as $student) {
            foreach ($student['issues'] as $issue) {
                $errors[] = $issue;
            }
        }

        foreach ($domains as $domain) {
            foreach ($domain['issues'] as $issue) {
                $errors[] = $issue;
            }
        }

        if (! $source->isExportable()) {
            $missing = $source->missingBands();

            $errors[] = $missing === []
                ? 'A escala de classificação desta turma não tem correspondência INOVAR configurada.'
                : 'Esta escala não tem correspondência INOVAR configurada para todas as menções: '.implode(', ', $missing).'.';
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  list<array<string, mixed>>  $students
     * @param  list<array<string, mixed>>  $values
     * @return list<string>
     */
    protected function warnings(array $students, array $values): array
    {
        $warnings = [];

        $partial = count(array_filter($values, fn (array $row): bool => $row['writable'] && $row['coverage_warning']));

        if ($partial > 0) {
            // «1 resultado foi», «4 resultados foram» — a teacher reading «1
            // menções» learns that nobody read the sentence.
            $warnings[] = $partial === 1
                ? '1 resultado foi calculado com informação parcial.'
                : "{$partial} resultados foram calculados com informação parcial.";
        }

        $blank = count(array_filter($values, fn (array $row): bool => ! $row['writable']));

        if ($blank > 0) {
            $warnings[] = $blank === 1
                ? '1 célula fica por preencher por não haver menção — nunca é preenchida com Fraco.'
                : "{$blank} células ficam por preencher por não haver menção — nunca são preenchidas com Fraco.";
        }

        return $warnings;
    }

    /**
     * Which results were partial, whose they are, and what is behind each.
     *
     * One entry per (student, domain) that will be written from partial
     * evidence — a value that exists and rests on less than everything that was
     * expected. A cell with no mention at all is not here: there is nothing for
     * the coverage to be partial OF.
     *
     * @param  list<array<string, mixed>>  $values
     * @return list<array<string, mixed>>
     */
    protected function partialCoverage(array $values): array
    {
        $rows = [];

        foreach ($values as $value) {
            if (! $value['writable'] || ! $value['coverage_warning']) {
                continue;
            }

            $rows[] = [
                'student' => $value['student'],
                'domain' => $value['domain'],
                // Already grouped by instrument and carrying its date: a student
                // absent from a three-question test is one occurrence, not three.
                'elements' => $value['coverage_elements'],
            ];
        }

        return $rows;
    }

    /**
     * @return Collection<int, Enrollment>
     */
    protected function enrollments(SchoolClass $class): Collection
    {
        return Enrollment::query()
            ->where('class_id', $class->id)
            ->with('student.identity')
            ->orderBy('class_number')
            ->get();
    }

    protected function normalize(string $value): string
    {
        return Str::of($value)->squish()->lower()->value();
    }

    /**
     * Trimmed, and nothing else. A leading zero is part of somebody's
     * identifier, not formatting to be tidied away.
     */
    protected function normalizeNumber(string $value): string
    {
        return trim($value);
    }
}
