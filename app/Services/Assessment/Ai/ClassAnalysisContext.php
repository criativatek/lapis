<?php

namespace App\Services\Assessment\Ai;

use App\Services\Assessment\BuildClassStatistics;
use App\Support\Privacy\AiContext;
use App\Support\Privacy\Pseudonyms;

/**
 * The minimum a reading of a class needs, expressed as an `AiContext`.
 *
 * THIS CLASS OWNS THE ALLOWLIST AND NOTHING ELSE. Deciding WHICH of the
 * statistics a model may see is a pedagogical judgement that only this feature
 * can make; pseudonymising, serialising and sanitising are the AI Core's job
 * and are not reimplemented here (ADR-0007, contract §2). What this builds is
 * the `allowlist` step of `allowlist → pseudonimizar → serializar → sanitizar`;
 * `AiContext::add()` performs the second as each value goes in, and
 * `toPayload()` performs the third and fourth.
 *
 * THE ROSTER IS ORDERED BY RESULT, AND THAT IS THE PSEUDONYMISATION DECISION
 * THIS CLASS STILL MAKES. `Pseudonyms::of()` is positional — the first name it
 * is given becomes «Aluno A». `BuildClassStatistics` hands its students over in
 * pauta order, which is alphabetical or by class number, and both leak a little
 * about who «Aluno A» is. Sorting by the figure under discussion before
 * building the map severs that correspondence, and as a side effect produces
 * the order a reader looking for a pattern would have wanted anyway.
 *
 * THE REAL NAME IS WHAT GOES INTO `add()`, deliberately, and never the
 * pseudonym. Handing `AiContext` the already-substituted string would leave
 * the Core with nothing to do and this class with a second, private
 * pseudonymisation scheme to keep in step with the first. Passing the name and
 * letting `add()` replace it means one implementation, one numbering, and a
 * substitution that `AiContext::fields()` can be asked to prove happened —
 * which is exactly what `ClassAnalysisContextTest` asks it.
 *
 * NOTHING IS CALCULATED HERE. Every number below was already decided by
 * `BuildClassStatistics`, which itself only aggregates what
 * `BuildResultsProgression` decided — this class selects, renames and drops.
 * Recomputing so much as a percentage would be a second opinion about a figure
 * that already has one (§0 of the Estatística brief), and would let the model
 * be shown a number the screen never displayed.
 */
final class ClassAnalysisContext
{
    /**
     * How many pseudonymised student rows travel. A class larger than this is
     * summarised by its distribution alone — thirty individual rows do not
     * make a pattern clearer than the bands already do, and each one is a
     * little more exposure for a little less signal.
     */
    public const MAX_STUDENT_ROWS = 30;

    /**
     * @param  array<string, mixed>  $statistics  exactly what `BuildClassStatistics::for()` returned.
     */
    public static function build(array $statistics, string $subject, ?string $gradeLevel): AiContext
    {
        $students = self::rows($statistics['students'] ?? []);

        // The map, built from the result-ordered roster. Every value added
        // below passes through it, so a name reaching the context by any route
        // — a student row, a domain a teacher named after somebody — is
        // replaced at the moment it goes in.
        $context = AiContext::about(Pseudonyms::of(array_values(array_filter(
            array_column($students, 'name'),
            fn (?string $name): bool => $name !== null && trim($name) !== '',
        ))));

        /** @var array<string, mixed> $summary */
        $summary = is_array($statistics['summary'] ?? null) ? $statistics['summary'] : [];
        /** @var array<string, mixed>|null $period */
        $period = is_array($statistics['selected_period'] ?? null) ? $statistics['selected_period'] : null;
        /** @var array<string, mixed>|null $scale */
        $scale = is_array($statistics['scale'] ?? null) ? $statistics['scale'] : null;
        /** @var array<string, mixed> $primary */
        $primary = is_array($statistics['primary'] ?? null) ? $statistics['primary'] : [];

        // Structural, and deliberately thin: what is being taught and at what
        // level, because «62% em Matemática do 7.º ano» and the same figure in
        // another subject are not the same observation. The class's own label
        // and ulid are NOT here — they identify the group, and nothing in the
        // reading depends on knowing which group it is.
        $context
            ->add('Disciplina', $subject)
            ->add('Ano de escolaridade', $gradeLevel)
            ->add('Período em análise', $period === null ? null : self::scalar($period['label'] ?? null))
            ->add('Escala', $scale === null ? null : self::scalar($scale['name'] ?? null))
            ->add('Leitura principal', self::scalar($primary['label'] ?? null));

        self::addSummary($context, $summary);
        self::addDistribution($context, $statistics['distribution'] ?? []);
        self::addEvolution($context, $statistics['evolution'] ?? []);
        self::addDomains($context, $statistics['domain_statistics'] ?? []);
        self::addStudents($context, $students);

        return $context;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private static function addSummary(AiContext $context, array $summary): void
    {
        /** @var array<string, mixed>|null $band */
        $band = is_array($summary['most_common_band'] ?? null) ? $summary['most_common_band'] : null;
        /** @var array<string, mixed> $success */
        $success = is_array($summary['success'] ?? null) ? $summary['success'] : [];

        $context
            ->add('Alunos na turma', self::scalar($summary['students_total'] ?? null))
            ->add('Alunos com resultado', self::scalar($summary['students_with_result'] ?? null))
            ->add('Alunos sem resultado', self::scalar($summary['students_without_result'] ?? null))
            ->add('Média da turma', self::scalar($summary['primary_average'] ?? null))
            ->add('Nível mais frequente', $band === null ? null : self::scalar($band['label'] ?? null))
            ->add('Sucesso (alunos)', self::scalar($success['succeeded'] ?? null))
            ->add('Sucesso (colocados na escala)', self::scalar($success['placed'] ?? null))
            ->add('Taxa de sucesso (%)', self::scalar($success['rate'] ?? null))
            ->add('Resultados com cobertura parcial', self::scalar($summary['partial_coverage_count'] ?? null));
    }

    /**
     * @param  mixed  $bands
     */
    private static function addDistribution(AiContext $context, $bands): void
    {
        if (! is_array($bands)) {
            return;
        }

        // `scale_level_id` and `sequence` are dropped: a database key and an
        // ordering, and the list arrives in order already.
        $context->addList('Distribuição pela escala', array_values(array_map(
            fn (array $band): string => self::scalar($band['label'] ?? null)
                .': '.self::scalar($band['count'] ?? null).' aluno(s), '
                .self::scalar($band['percentage'] ?? null).'%'
                .(($band['is_negative'] ?? false) === true ? ' (nível negativo)' : ''),
            array_filter($bands, 'is_array'),
        )));
    }

    /**
     * @param  mixed  $evolution
     */
    private static function addEvolution(AiContext $context, $evolution): void
    {
        // `array_key_exists` rather than `?? null`: the question here is
        // genuinely whether the read model produced a comparison at all — a
        // first period has none — and `??` cannot tell an absent key from a
        // present null one.
        if (! is_array($evolution) || ! array_key_exists('comparable', $evolution) || $evolution['comparable'] === null) {
            return;
        }

        /** @var array<string, mixed> $percentages */
        $percentages = is_array($evolution['percentages'] ?? null) ? $evolution['percentages'] : [];

        $context
            // No `?? null` here: the guard above already established that this
            // key exists and is not null, and a defensive fallback that can
            // never fire only makes the guard look optional.
            ->add('Evolução: alunos comparáveis', self::scalar($evolution['comparable']))
            ->add('Evolução: variação média (pontos percentuais)', self::scalar($evolution['average_change'] ?? null))
            ->add('Evolução: progrediram (%)', self::scalar($percentages['progressed'] ?? null))
            ->add('Evolução: mantiveram (%)', self::scalar($percentages['stable'] ?? null))
            ->add('Evolução: regrediram (%)', self::scalar($percentages['regressed'] ?? null));
    }

    /**
     * @param  mixed  $domains
     */
    private static function addDomains(AiContext $context, $domains): void
    {
        if (! is_array($domains)) {
            return;
        }

        // The domain's NAME, which a teacher wrote and which is what makes the
        // reading legible — and which, being teacher-authored, is exactly the
        // kind of field that could contain somebody's name. It goes through
        // `add()` like everything else, so it is pseudonymised if it does.
        $context->addList('Por domínio', array_values(array_map(
            fn (array $domain): string => self::scalar($domain['label'] ?? null)
                .': média '.self::scalar($domain['period_average'] ?? null)
                .', acumulada '.self::scalar($domain['accumulated_average'] ?? null)
                .', evolução '.self::scalar($domain['evolution_average'] ?? null)
                .', sucesso '.self::scalar($domain['success_rate'] ?? null).'%'
                .', sem resultado '.self::scalar($domain['students_without_result'] ?? null),
            array_filter($domains, 'is_array'),
        )));
    }

    /**
     * @param  list<array{name: ?string, average: ?string, band: ?string, movement: ?string, partial: bool}>  $students
     */
    private static function addStudents(AiContext $context, array $students): void
    {
        if ($students === []) {
            return;
        }

        $context->addList('Resultado por aluno', array_map(
            fn (array $student): string => (string) ($student['name'] ?? 'Sem nome')
                .': '.self::scalar($student['average'])
                .', nível '.self::scalar($student['band'])
                .', evolução '.self::scalar($student['movement'])
                .($student['partial'] ? ', cobertura parcial' : ''),
            $students,
        ));
    }

    /**
     * The rows, allowlisted and sorted by result.
     *
     * THE ALLOWLIST IS THE POINT. This builds a new array from named keys
     * rather than unsetting the identifying ones from the row it was given —
     * so a field added to `BuildClassStatistics::students()` next year arrives
     * here as nothing at all, instead of arriving as a leak nobody noticed.
     * `class_number` and `enrollment_id` are never read; `name` is read for
     * one purpose only, which is to be replaced.
     *
     * @param  mixed  $students
     * @return list<array{name: ?string, average: ?string, band: ?string, movement: ?string, partial: bool}>
     */
    private static function rows($students): array
    {
        if (! is_array($students)) {
            return [];
        }

        $rows = [];

        foreach ($students as $student) {
            if (! is_array($student)) {
                continue;
            }

            /** @var array<string, mixed>|null $band */
            $band = is_array($student['band'] ?? null) ? $student['band'] : null;
            /** @var array<string, mixed>|null $movement */
            $movement = is_array($student['evolution'] ?? null) ? $student['evolution'] : null;

            $rows[] = [
                'name' => is_string($student['name'] ?? null) ? $student['name'] : null,
                'average' => self::displayed($student['primary_average'] ?? null),
                'band' => $band === null ? null : self::nullableScalar($band['label'] ?? null),
                'movement' => $movement === null ? null : self::nullableScalar($movement['direction'] ?? null),
                'partial' => ($student['coverage_warning'] ?? false) === true,
            ];
        }

        // Descending by result, with the students who have none last. This is
        // what severs the pseudonym from the pauta position — see the class
        // docblock.
        usort($rows, function (array $a, array $b): int {
            if ($a['average'] === null || $b['average'] === null) {
                return ($a['average'] === null ? 1 : 0) <=> ($b['average'] === null ? 1 : 0);
            }

            return (float) $b['average'] <=> (float) $a['average'];
        });

        return array_slice($rows, 0, self::MAX_STUDENT_ROWS);
    }

    /**
     * A per-student average, at the precision the application displays.
     *
     * NOT A CALCULATION — A FORMAT, and the distinction is the whole reason
     * this method has a docblock. The class-level figures arrive from
     * `BuildClassStatistics::mean()` already rounded to
     * `BuildClassStatistics::PRECISION`, which that class's own docblock
     * describes as «the same precision the rest of the application shows, so
     * that 72,4% on Resultados is 72,4% here». The per-student figures do not:
     * they come straight off the progression's `normalizedValue`, unrounded,
     * carrying eight or more decimals that no screen has ever displayed.
     *
     * Sending them raw was actively harmful in two ways. A model asked to cite
     * a figure exactly would cite «89.12345678», a number the teacher cannot
     * reconcile with anything on the page — the precise failure
     * `ClassAnalysisPrompt` exists to prevent. And a run of six or more digits
     * is exactly what `AiPayloadSanitizer`'s `long_numbers` rule removes, so
     * the model received «89.[número removido]» and the reading was built on a
     * mutilated figure.
     *
     * Rounding here to the constant the application already publishes is
     * therefore not a second opinion about the number; it is sending the same
     * number the screen sends, which is what the source-of-truth rule asks for.
     */
    private static function displayed(mixed $value): ?string
    {
        $plain = self::nullableScalar($value);

        if ($plain === null || ! is_numeric($plain)) {
            return $plain;
        }

        return number_format((float) $plain, BuildClassStatistics::PRECISION, '.', '');
    }

    /** For display inside a composed line: a missing figure says so. */
    private static function scalar(mixed $value): string
    {
        $plain = self::nullableScalar($value);

        return $plain ?? 'não disponível';
    }

    private static function nullableScalar(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_scalar($value) || is_bool($value)) {
            return null;
        }

        return (string) $value;
    }
}
