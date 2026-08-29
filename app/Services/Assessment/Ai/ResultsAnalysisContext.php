<?php

namespace App\Services\Assessment\Ai;

use App\Support\Privacy\AiContext;
use App\Support\Privacy\Pseudonyms;
use Illuminate\Contracts\Support\Arrayable;

/**
 * The minimum a reading of a period's RESULTS needs, expressed as an
 * `AiContext`.
 *
 * IT IS BUILT FROM THE SCREEN'S OWN PAYLOAD, and that is the point rather than
 * a convenience. `ResultsController::show()` assembles the rows from
 * `ClassResultsCalculator::forPeriod()`, `CoverageExplanation` and
 * `BuildResultsProgression`; this class is handed that finished array and
 * SELECTS from it. Nothing is fetched again, nothing is joined, and nothing is
 * computed — so a figure that reaches the engine is by construction a figure
 * the teacher was looking at when they pressed the button.
 *
 * WHAT «NÃO RECALCULAR» MEANS HERE, EXACTLY. Two counts are produced below —
 * how many students have a result at all, and how many results carry a
 * coverage warning. Counting rows the engine already decided is not computing
 * a result: no grade, no average, no percentage and no weight is derived
 * anywhere in this file, and the per-student and per-domain figures are copied
 * verbatim from the payload. The distinction matters because those two counts
 * are what make the CAUTELAS section answerable at all — a model that cannot
 * see how thin the evidence is will read a partial period as a finished one.
 *
 * THE ROSTER IS ORDERED BY RESULT, for the same reason
 * `ClassAnalysisContext` does it. `Pseudonyms::of()` is positional: the first
 * name it is given becomes «Aluno A». The screen's rows arrive in pauta order,
 * which is alphabetical or by class number, and both leak a little about who
 * «Aluno A» is. Sorting by the figure under discussion before building the map
 * severs that correspondence, and as a side effect produces the order a reader
 * looking for a pattern would have wanted anyway.
 *
 * THE REAL NAME IS WHAT GOES INTO `add()`, deliberately, and never the
 * pseudonym — so the Core does the substitution once, with one numbering,
 * and `AiContext::fields()` can be asked to prove it happened.
 *
 * WHAT IS DELIBERATELY NOT SENT: the class label, the class ulid, every
 * enrolment ulid, every student's class number, every photo URL, and the
 * teacher's own identity. None of them changes a reading of results, and each
 * of them narrows who «Aluno A» could be.
 */
final class ResultsAnalysisContext
{
    /**
     * How many pseudonymised student rows travel. A class larger than this is
     * described by its counts alone — thirty individual rows do not make a
     * pattern clearer, and each one is a little more exposure for a little
     * less signal. The same ceiling `ClassAnalysisContext` uses.
     */
    public const MAX_STUDENT_ROWS = 30;

    /**
     * The precision every figure is sent at.
     *
     * NOT A CALCULATION — A FORMAT, and the same one the application displays.
     * The per-student and per-domain figures on this screen come straight off
     * the calculation outcome's `normalizedValue`, unrounded, carrying eight or
     * more decimals no screen has ever shown. Sending them raw is harmful
     * twice: a model asked to cite a figure exactly would cite
     * «89.12345678», which the teacher cannot reconcile with the page, and a
     * run of six or more digits is exactly what `AiPayloadSanitizer`'s
     * `long_numbers` rule removes — so the model would receive
     * «89.[número removido]» and build its reading on a mutilated figure.
     */
    public const PRECISION = 1;

    /**
     * @param  array<string, mixed>  $payload  exactly what `ResultsController::show()` renders.
     * @param  string  $subject  the class's subject — read off the model, never off the payload's label.
     * @param  string|null  $gradeLevel  the year of schooling, when the class records one.
     */
    public static function build(array $payload, string $subject, ?string $gradeLevel): AiContext
    {
        $rows = self::rows($payload);

        $context = AiContext::about(Pseudonyms::of(array_values(array_filter(
            array_column($rows, 'name'),
            fn (?string $name): bool => $name !== null && trim($name) !== '',
        ))));

        /** @var array<string, mixed> $schoolClass */
        $schoolClass = is_array($payload['schoolClass'] ?? null) ? $payload['schoolClass'] : [];

        $context
            ->add('Disciplina', $subject)
            ->add('Ano de escolaridade', $gradeLevel)
            ->add('Período em análise', self::selectedPeriodLabel($payload))
            ->add('Escala', self::nullableScalar($schoolClass['scale_name'] ?? null))
            ->add('Leitura principal', self::nullableScalar($payload['weightedAverageLabel'] ?? null))
            // Whether a comparison with the period before is even possible. The
            // prompt is told not to invent one; this is what makes that
            // instruction checkable rather than hopeful.
            ->add('Existe período anterior para comparação', ($payload['isFirstPeriod'] ?? false) === true ? 'não' : 'sim');

        self::addDomains($context, $payload);
        self::addCoverage($context, $rows);
        self::addStudents($context, $rows);

        return $context;
    }

    /**
     * The domains this period was assessed on, by name.
     *
     * TEACHER-AUTHORED, AND THEREFORE EXACTLY THE KIND OF FIELD THAT COULD
     * CONTAIN SOMEBODY'S NAME. A domain called «Apoio ao João» is not
     * hypothetical. It goes through `add()` like everything else, so it is
     * pseudonymised if it does.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function addDomains(AiContext $context, array $payload): void
    {
        $names = [];

        foreach (self::listOf($payload['domains'] ?? null) as $domain) {
            $name = self::nullableScalar($domain['name'] ?? null);

            if ($name !== null && $name !== '—') {
                $names[] = $name;
            }
        }

        if ($names !== []) {
            $context->addList('Domínios avaliados', $names);
        }
    }

    /**
     * How much evidence there is, as counts.
     *
     * THE MOST IMPORTANT FIELDS IN THIS CONTEXT, and the least interesting to
     * read. Everything else describes results; these two say how far the
     * results can be trusted to describe the period. Without them a model
     * reads «três alunos com resultado» as a finding about three alunos rather
     * than as a period that has barely started.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private static function addCoverage(AiContext $context, array $rows): void
    {
        $withResult = 0;
        $partial = 0;

        foreach ($rows as $row) {
            if (($row['has_value'] ?? false) === true) {
                $withResult++;
            }

            if (($row['coverage_warning'] ?? false) === true) {
                $partial++;
            }
        }

        $context
            ->add('Alunos na turma', count($rows))
            ->add('Alunos com resultado neste período', $withResult)
            ->add('Alunos sem resultado neste período', count($rows) - $withResult)
            ->add('Resultados com cobertura parcial', $partial);
    }

    /**
     * One line per student, result first.
     *
     * THE DOMAINS TRAVEL INLINE, on the same line as the overall figure,
     * because that is the comparison the reading is about: «este aluno está
     * acima da média mas com Escrita a metade» is one observation, and
     * splitting it across two fields makes it two.
     *
     * A DOMAIN WITH A COVERAGE WARNING SAYS SO, INLINE. The prompt forbids
     * characterising such a domain as strong or weak, and an instruction the
     * data cannot support is a wish. This is the data that supports it.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private static function addStudents(AiContext $context, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $context->addList('Resultado por aluno', array_map(
            static function (array $row): string {
                $parts = [
                    (string) ($row['name'] ?? 'Sem nome'),
                    ': ',
                    $row['overall'] ?? 'sem resultado',
                ];

                if ($row['band'] !== null) {
                    $parts[] = ', nível '.$row['band'];
                }

                if ($row['coverage_warning'] === true) {
                    $parts[] = ' (cobertura parcial)';
                }

                if ($row['evolution'] !== null) {
                    $parts[] = ', evolução '.$row['evolution'];
                }

                if ($row['self_assessment'] !== null) {
                    $parts[] = ', autoavaliação '.$row['self_assessment'];
                }

                if ($row['classification'] !== null) {
                    $parts[] = ', classificação decidida '.$row['classification'];
                }

                if ($row['domains'] !== []) {
                    $parts[] = '; por domínio: '.implode('; ', $row['domains']);
                }

                return implode('', $parts);
            },
            $rows,
        ));
    }

    /**
     * The rows, allowlisted and sorted by result.
     *
     * THE ALLOWLIST IS THE POINT. This builds a new array from named keys
     * rather than unsetting the identifying ones from the row it was given —
     * so a field added to `ResultsController::show()` next year arrives here as
     * nothing at all, instead of arriving as a leak nobody noticed.
     * `enrollment_ulid`, `class_number` and `photo_url` are never read; `name`
     * is read for one purpose only, which is to be replaced.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private static function rows(array $payload): array
    {
        $domainNames = self::domainNames($payload);

        $rows = [];

        foreach (self::listOf($payload['rows'] ?? null) as $row) {
            $rows[] = [
                'name' => is_string($row['name'] ?? null) ? $row['name'] : null,
                'overall' => self::figure($row['overall'] ?? null),
                'band' => self::proposalLabel($row['proposal'] ?? null),
                'has_value' => ($row['has_value'] ?? false) === true,
                'coverage_warning' => ($row['coverage_warning'] ?? false) === true,
                'evolution' => self::direction($row['evolution'] ?? null),
                'self_assessment' => self::selfAssessment($row['self_assessment'] ?? null),
                'classification' => self::classification($row['classification'] ?? null),
                'domains' => self::domainCells($row['domains'] ?? null, $domainNames),
            ];
        }

        // Descending by result, with the students who have none last. This is
        // what severs the pseudonym from the pauta position — see the class
        // docblock.
        usort($rows, static function (array $a, array $b): int {
            if ($a['overall'] === null || $b['overall'] === null) {
                return ($a['overall'] === null ? 1 : 0) <=> ($b['overall'] === null ? 1 : 0);
            }

            return (float) $b['overall'] <=> (float) $a['overall'];
        });

        return array_slice($rows, 0, self::MAX_STUDENT_ROWS);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private static function domainNames(array $payload): array
    {
        $names = [];

        foreach (self::listOf($payload['domains'] ?? null) as $domain) {
            $id = $domain['id'] ?? null;
            $name = self::nullableScalar($domain['name'] ?? null);

            if (is_int($id) && $name !== null) {
                $names[$id] = $name;
            }
        }

        return $names;
    }

    /**
     * @param  array<int, string>  $domainNames
     * @return list<string>
     */
    private static function domainCells(mixed $domains, array $domainNames): array
    {
        if (! is_array($domains)) {
            return [];
        }

        $cells = [];

        foreach ($domains as $domain) {
            if (! is_array($domain)) {
                continue;
            }

            $id = $domain['domain_id'] ?? null;
            $name = is_int($id) ? ($domainNames[$id] ?? null) : null;

            if ($name === null) {
                continue;
            }

            $value = self::figure($domain['value'] ?? null);
            $cell = $name.' '.($value ?? 'sem resultado');

            if (($domain['warning'] ?? false) === true) {
                $cell .= ' (cobertura parcial)';
            }

            $movement = self::direction($domain['evolution'] ?? null);

            if ($movement !== null) {
                $cell .= ' ['.$movement.']';
            }

            $cells[] = $cell;
        }

        return $cells;
    }

    /** The proposal's own label — «Bom», «4», «80%» — never its internal value. */
    private static function proposalLabel(mixed $proposal): ?string
    {
        if (! is_array($proposal)) {
            return null;
        }

        return self::nullableScalar($proposal['label'] ?? null);
    }

    /** Trend, never performance: the word the screen shows, not a number. */
    private static function direction(mixed $evolution): ?string
    {
        if (! is_array($evolution)) {
            return null;
        }

        return self::nullableScalar($evolution['direction'] ?? null);
    }

    /**
     * What the student said about themselves, and whether it lines up.
     *
     * THE COMPARISON TRAVELS AS A DIRECTION, NEVER AS A GAP IN POINTS. «acima»
     * is an observation; «12 pontos acima» invites a model to characterise how
     * wrong a child is about their own work, which is not a thing this product
     * says about children (§11).
     */
    private static function selfAssessment(mixed $selfAssessment): ?string
    {
        if (! is_array($selfAssessment)) {
            return null;
        }

        $label = self::nullableScalar($selfAssessment['label'] ?? null)
            ?? self::nullableScalar($selfAssessment['level'] ?? null);

        if ($label === null) {
            return null;
        }

        $comparison = is_array($selfAssessment['comparison'] ?? null)
            ? self::nullableScalar($selfAssessment['comparison']['direction'] ?? null)
            : null;

        return $comparison === null ? $label : $label.' ('.$comparison.' da evidência)';
    }

    /** The teacher's own decision, when they have made one. Reported, never judged. */
    private static function classification(mixed $classification): ?string
    {
        if (! is_array($classification)) {
            return null;
        }

        $final = $classification['final'] ?? null;

        if (is_array($final)) {
            return self::nullableScalar($final['label'] ?? null);
        }

        return self::nullableScalar($final);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function selectedPeriodLabel(array $payload): ?string
    {
        foreach (self::listOf($payload['periods'] ?? null) as $period) {
            if (($period['selected'] ?? false) === true) {
                return self::nullableScalar($period['label'] ?? null);
            }
        }

        return null;
    }

    /**
     * One of the payload's collections, as a plain list of arrays.
     *
     * `ResultsController::show()` hands Inertia a MIXTURE of plain arrays and
     * Illuminate collections — `rows` is an array, `periods` and `domains` are
     * collections — because Inertia serialises both the same way and the
     * controller never had a reason to care. This class does: `is_array()` on a
     * `Collection` is false, and a context that silently skipped `periods`
     * would omit the period label without failing anything.
     *
     * @return list<array<string, mixed>>
     */
    private static function listOf(mixed $value): array
    {
        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        }

        if (! is_iterable($value)) {
            return [];
        }

        $rows = [];

        foreach ($value as $row) {
            if ($row instanceof Arrayable) {
                $row = $row->toArray();
            }

            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** A figure at the precision the application displays. See `PRECISION`. */
    private static function figure(mixed $value): ?string
    {
        $plain = self::nullableScalar($value);

        if ($plain === null || ! is_numeric($plain)) {
            return $plain;
        }

        return number_format((float) $plain, self::PRECISION, '.', '');
    }

    private static function nullableScalar(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_scalar($value) || is_bool($value)) {
            return null;
        }

        return (string) $value;
    }
}
