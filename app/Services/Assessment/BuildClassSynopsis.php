<?php

namespace App\Services\Assessment;

use App\Models\AcademicPeriod;
use App\Models\ClassificationScope;
use App\Models\Enrollment;
use App\Models\EvaluationSheetExport;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\SheetMomentKind;
use App\Support\Assessment\DomainColorPalette;
use Illuminate\Support\Collection;

/**
 * O QUADRO SÍNTESE — o ano letivo de uma turma numa estrutura só.
 *
 * AGREGA, NÃO GUARDA. Todos os números desta leitura já existem noutro sítio e
 * continuam a ser desse sítio: os resultados vêm do motor de cálculo através de
 * `BuildEvaluationSheet`, as fotografias vêm de `evaluation_sheet_exports`, os
 * elementos vêm dos próprios instrumentos, a média contínua vem de
 * `ContinuousAssessment`, que por sua vez vem dos resultados formais. Nenhuma
 * tabela nova foi criada para esta página e nenhuma tem de ser: uma segunda
 * cópia dos quantitativos seria uma segunda verdade a manter em dia (§6).
 *
 * OS DOIS TIPOS DE MOMENTO, E A DIFERENÇA ENTRE ELES (§9):
 *
 *   FOTOGRAFIA (intercalar)  Lê-se da pauta guardada, e só de lá. Uma intercalar
 *                            não tem números próprios enquanto ninguém a guarda
 *                            — o estado avaliativo é um só, o de hoje —, e é o
 *                            ato de guardar que fixa o que era verdade naquele
 *                            dia. Um momento intercalar sem fotografia aparece
 *                            vazio, que é a resposta verdadeira (§29).
 *
 *   FORMAL (a unidade)       Lê-se do estado atual. A pauta de um período é
 *                            reeditável até ao fim, e o que ela diz hoje é o que
 *                            é verdade hoje. É esta — e nunca a intercalar —
 *                            que entra na avaliação contínua (§7).
 *
 * NADA RECALCULA UMA FOTOGRAFIA. O que foi guardado lê-se como foi guardado:
 * se em novembro a proposta era «Suficiente», o Quadro continua a dizer
 * «Suficiente» em junho, mesmo que os mesmos elementos dessem hoje «Bom»
 * (§15). A única coisa que a fotografia vai buscar ao presente é a POSIÇÃO do
 * nível na escala, para poder ser pintada e comparada — e quando a escala mudou
 * ao ponto de já não a ter, fica sem cor em vez de ficar com a errada.
 *
 * UMA PAUTA DE TÍTULO LIVRE NÃO É UM MOMENTO ESTRUTURAL (§23, §28). Vive no
 * Histórico, que é onde um registo vive, e não entra nesta cronologia nem serve
 * de termo de comparação para tendência nenhuma: a sua data e o seu sentido são
 * o que o professor quis, e uma sequência construída sobre isso não seria uma
 * sequência.
 */
class BuildClassSynopsis
{
    public function __construct(
        protected BuildEvaluationSheet $sheets,
        protected BuildClassElements $elements,
        protected ContinuousAssessment $continuous,
    ) {}

    /**
     * @return array{
     *     moments: list<array<string, mixed>>,
     *     domains: list<array<string, mixed>>,
     *     students: list<array<string, mixed>>,
     *     continuous: array<string, mixed>,
     *     elements: list<array<string, mixed>>,
     *     periods: list<array<string, mixed>>,
     * }
     */
    public function for(SchoolClass $class): array
    {
        $periods = AcademicPeriod::query()
            ->where('academic_year_id', $class->academic_year_id)
            ->orderBy('sequence')
            ->get();

        $scale = $class->profileVersion?->scale()->with('levels')->first();

        if ($periods->isEmpty() || $class->assessment_profile_version_id === null) {
            return [
                'moments' => [],
                'domains' => [],
                'students' => [],
                'continuous' => ['units' => [], 'students' => []],
                'elements' => [],
                'periods' => [],
            ];
        }

        // A pauta viva de cada unidade formal — uma leitura por período, que é
        // exatamente o que a Pauta de Avaliação faz quando o professor abre esse
        // separador. É daqui que sai o resultado formal, e é ele que a avaliação
        // contínua vai usar.
        $live = [];
        foreach ($periods as $period) {
            $live[(int) $period->getKey()] = $this->sheets->for($class, $period, ClassificationScope::Period);
        }

        $snapshots = $this->structuralSnapshots($class, $periods);
        $moments = $this->moments($periods, $snapshots);
        $domains = $this->domains($live, $periods);
        $elements = $this->elements->for($class, $periods);

        $formalByPeriod = [];
        foreach ($live as $periodId => $sheet) {
            $row = [];
            foreach ($sheet['students'] as $student) {
                $row[(int) $student['enrollment_id']] = $student['overall']['normalized_value'] ?? null;
            }
            $formalByPeriod[$periodId] = $row;
        }

        $continuous = $this->continuous->for($class, $periods, $formalByPeriod);

        $students = $this->students($class, $live, $snapshots, $moments, $domains, $scale, $elements, $continuous);

        return [
            'moments' => $moments,
            'domains' => $domains,
            'students' => $students,
            'continuous' => ['units' => $continuous['units']],
            'elements' => $elements['elements'],
            'periods' => array_values($periods->map(fn (AcademicPeriod $period): array => [
                'id' => (int) $period->getKey(),
                'ulid' => (string) $period->ulid,
                'label' => (string) $period->label,
                'kind_label' => $period->kind->label(),
                'sequence' => (int) $period->sequence,
            ])->all()),
        ];
    }

    /**
     * A cronologia dos momentos estruturais: para cada unidade temporal, o
     * intercalar e depois o que a fecha (§9).
     *
     * @param  Collection<int, AcademicPeriod>  $periods
     * @param  array<string, EvaluationSheetExport>  $snapshots
     * @return list<array<string, mixed>>
     */
    protected function moments(Collection $periods, array $snapshots): array
    {
        $moments = [];

        foreach ($periods as $period) {
            foreach (SheetMomentKind::inOrder() as $kind) {
                $key = self::momentKey((int) $period->getKey(), $kind);
                $snapshot = $snapshots[$key] ?? null;
                $isFormal = $kind === SheetMomentKind::Final;

                $moments[] = [
                    'key' => $key,
                    'period_id' => (int) $period->getKey(),
                    'period_ulid' => (string) $period->ulid,
                    'period_label' => (string) $period->label,
                    'kind' => $kind->value,
                    'label' => $kind->tabLabel($period),
                    'moment_label' => $kind->momentLabel($period),
                    'kind_label' => $period->kind->label(),
                    // O QUE DISTINGUE OS DOIS, dito em dados e não em cor: um é
                    // uma fotografia informativa, o outro é o resultado formal
                    // da unidade. Só o segundo entra na média contínua (§7).
                    'is_formal' => $isFormal,
                    'source' => $isFormal ? 'live' : ($snapshot !== null ? 'snapshot' : 'none'),
                    'snapshot' => $snapshot === null ? null : [
                        'ulid' => (string) $snapshot->ulid,
                        'moment_label' => (string) $snapshot->moment_label,
                        'effective_at' => $snapshot->effective_at?->toDateString(),
                        'kept_at' => $snapshot->exported_at->toDateString(),
                    ],
                ];
            }
        }

        return $moments;
    }

    /**
     * A fotografia mais recente de cada momento estrutural.
     *
     * SÓ AS QUE DIZEM QUE MOMENTO SÃO. A partir da versão 2 do payload uma
     * fotografia declara se é intercalar ou final; as anteriores não o dizem, e
     * atribuir-lhes um agora seria escrever no passado uma afirmação que ninguém
     * fez (ver `CaptureEvaluationSheet::CURRENT_VERSION`). Ficam no Histórico,
     * onde continuam inteiras e legíveis.
     *
     * SÓ AS DO PRÓPRIO PRODUTO, e não as da grelha do Inovar: um ficheiro
     * exportado para outro sistema é a mesma pauta vista de outro ângulo, e
     * mostrá-lo aqui como se fosse um momento próprio duplicaria a cronologia.
     *
     * A MAIS RECENTE GANHA. Um professor que guardou a intercalar três vezes
     * corrigiu-se duas; a última é a que ele quis deixar.
     *
     * @param  Collection<int, AcademicPeriod>  $periods
     * @return array<string, EvaluationSheetExport>
     */
    protected function structuralSnapshots(SchoolClass $class, Collection $periods): array
    {
        $exports = EvaluationSheetExport::query()
            ->where('class_id', $class->getKey())
            ->whereIn('academic_period_id', $periods->pluck('id'))
            ->where('adapter', 'snapshot')
            ->where('scope', ClassificationScope::Period)
            ->orderBy('exported_at')
            ->orderBy('id')
            ->get();

        $byKey = [];

        foreach ($exports as $export) {
            // FAIL CLOSED, como no Histórico: uma fotografia que já não bate
            // certo com o seu próprio resumo não é prova de nada, e mostrá-la
            // aqui seria deixar ler uma pauta adulterada como se fosse a real.
            if (! $export->isIntact()) {
                continue;
            }

            $payload = $export->payload;
            $kind = $payload['moment']['kind'] ?? null;

            if (! is_string($kind)) {
                continue;
            }

            $moment = SheetMomentKind::tryFrom($kind);

            if ($moment === null) {
                continue;
            }

            $byKey[self::momentKey((int) $export->academic_period_id, $moment)] = $export;
        }

        return $byKey;
    }

    /**
     * Os domínios do perfil, com a cor pela qual se identificam no ecrã.
     *
     * Lidos da pauta viva do primeiro período que tenha domínios: são os do
     * perfil ativo da turma, e não mudam de período para período. A cor sai da
     * mesma paleta que a Pauta usa — identidade do domínio, nunca desempenho.
     *
     * @param  array<int, array<string, mixed>>  $live
     * @param  Collection<int, AcademicPeriod>  $periods
     * @return list<array<string, mixed>>
     */
    protected function domains(array $live, Collection $periods): array
    {
        foreach ($periods as $period) {
            $sheet = $live[(int) $period->getKey()] ?? null;

            if ($sheet !== null && $sheet['domains'] !== []) {
                return DomainColorPalette::decorate($sheet['domains']);
            }
        }

        return [];
    }

    /**
     * Cada aluno, com a sua linha ao longo do ano.
     *
     * @param  array<int, array<string, mixed>>  $live
     * @param  array<string, EvaluationSheetExport>  $snapshots
     * @param  list<array<string, mixed>>  $moments
     * @param  list<array<string, mixed>>  $domains
     * @param  array{elements: list<array<string, mixed>>, by_student: array<int, list<array<string, mixed>>>}  $elements
     * @param  array{units: list<array<string, mixed>>, students: array<int, array<string, mixed>>}  $continuous
     * @return list<array<string, mixed>>
     */
    protected function students(
        SchoolClass $class,
        array $live,
        array $snapshots,
        array $moments,
        array $domains,
        ?Scale $scale,
        array $elements,
        array $continuous,
    ): array {
        $roster = $this->roster($live);
        $rows = [];

        foreach ($roster as $enrollmentId => $identity) {
            $readings = [];
            $previousOverall = null;
            $previousLabel = null;
            $previousDomain = [];

            foreach ($moments as $moment) {
                $student = $this->studentAt($moment, $enrollmentId, $live, $snapshots);

                if ($student === null) {
                    $readings[] = [
                        'moment_key' => $moment['key'],
                        'available' => false,
                        'overall' => null,
                        'domains' => [],
                        'self_assessment' => null,
                        'classification' => null,
                        'trend' => null,
                    ];

                    continue;
                }

                $overall = SynopticReading::overallAppreciation($student, $scale);
                $trend = SynopticReading::trend($previousOverall, $overall, $previousLabel);

                $domainCells = [];
                foreach ($domains as $domain) {
                    $domainId = (int) $domain['domain_id'];
                    $row = $this->domainRowOf($student, $domainId);

                    if ($row === null) {
                        $domainCells[] = [
                            'domain_id' => $domainId,
                            'available' => false,
                            'normalized_value' => null,
                            'proposed' => null,
                            'decided' => null,
                            'current' => null,
                            'trend' => null,
                            'has_coverage_warning' => false,
                            'self_assessment' => null,
                        ];

                        continue;
                    }

                    $current = SynopticReading::domainAppreciation($row, $scale);
                    $domainTrend = SynopticReading::trend($previousDomain[$domainId] ?? null, $current, $previousLabel);

                    $domainCells[] = [
                        'domain_id' => $domainId,
                        'available' => true,
                        'normalized_value' => $row['normalized_value'] ?? null,
                        // AS DUAS COISAS VIAJAM SEMPRE, e cada uma no seu lugar
                        // (§17): a proposta continua consultável depois de o
                        // professor a ter substituído.
                        'proposed' => [
                            'code' => $row['scale_level_code'] ?? null,
                            'label' => $row['scale_level_label'] ?? null,
                        ],
                        'decided' => ($row['decided_scale_level_code'] ?? null) === null
                            && ($row['decided_scale_level_label'] ?? null) === null
                            ? null
                            : [
                                'code' => $row['decided_scale_level_code'] ?? null,
                                'label' => $row['decided_scale_level_label'] ?? null,
                            ],
                        'current' => $current,
                        'trend' => $domainTrend,
                        'has_coverage_warning' => (bool) ($row['has_coverage_warning'] ?? false),
                        'self_assessment' => $row['self_assessment'] ?? null,
                    ];

                    if (($current['sequence'] ?? null) !== null) {
                        $previousDomain[$domainId] = $current;
                    }
                }

                $readings[] = [
                    'moment_key' => $moment['key'],
                    'available' => true,
                    'overall' => [
                        'normalized_value' => $student['overall']['normalized_value'] ?? null,
                        'scale_value' => $student['overall']['scale_value'] ?? null,
                        'has_coverage_warning' => (bool) ($student['overall']['has_coverage_warning'] ?? false),
                        'current' => $overall,
                    ],
                    'domains' => $domainCells,
                    // A autoavaliação viaja como informação de apoio e nunca
                    // entra em conta nenhuma (§15, §61).
                    'self_assessment' => $student['self_assessment'] ?? null,
                    'classification' => $student['classification'] ?? null,
                    'coverage' => $student['coverage'] ?? null,
                    'trend' => $trend,
                ];

                if (($overall['sequence'] ?? null) !== null) {
                    $previousOverall = $overall;
                    $previousLabel = $moment['label'];
                }
            }

            $rows[] = [
                'enrollment_id' => $enrollmentId,
                'enrollment_ulid' => $identity['ulid'],
                'class_number' => $identity['class_number'],
                'name' => $identity['name'],
                'moments' => $readings,
                'continuous' => $continuous['students'][$enrollmentId] ?? null,
                'elements' => $elements['by_student'][$enrollmentId] ?? [],
            ];
        }

        return $rows;
    }

    /**
     * A pauta deste aluno neste momento — viva ou fotografada.
     *
     * @param  array<string, mixed>  $moment
     * @param  array<int, array<string, mixed>>  $live
     * @param  array<string, EvaluationSheetExport>  $snapshots
     * @return array<string, mixed>|null
     */
    protected function studentAt(array $moment, int $enrollmentId, array $live, array $snapshots): ?array
    {
        if ($moment['source'] === 'live') {
            $sheet = $live[(int) $moment['period_id']] ?? null;

            return $sheet === null ? null : $this->findStudent($sheet['students'], $enrollmentId);
        }

        if ($moment['source'] !== 'snapshot') {
            return null;
        }

        $export = $snapshots[$moment['key']] ?? null;

        if ($export === null) {
            return null;
        }

        $students = $export->payload['students'] ?? [];

        return is_array($students) ? $this->findStudent($students, $enrollmentId) : null;
    }

    /**
     * @param  array<int, mixed>  $students
     * @return array<string, mixed>|null
     */
    protected function findStudent(array $students, int $enrollmentId): ?array
    {
        foreach ($students as $student) {
            if (is_array($student) && (int) ($student['enrollment_id'] ?? 0) === $enrollmentId) {
                return $student;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $student
     * @return array<string, mixed>|null
     */
    protected function domainRowOf(array $student, int $domainId): ?array
    {
        foreach ($student['domains'] ?? [] as $domain) {
            if (is_array($domain) && (int) ($domain['domain_id'] ?? 0) === $domainId) {
                return $domain;
            }
        }

        return null;
    }

    /**
     * A pauta da turma, na ordem em que se chama a chamada.
     *
     * DELIBERADAMENTE TIRADA DAS PAUTAS VIVAS e não de uma fotografia: quem está
     * na turma é quem está na turma hoje. Um aluno que saiu continua a aparecer
     * nas fotografias em que estava, mas a lista de linhas é a de agora.
     *
     * @param  array<int, array<string, mixed>>  $live
     * @return array<int, array{ulid: string|null, class_number: int|null, name: string}>
     */
    protected function roster(array $live): array
    {
        $roster = [];

        foreach ($live as $sheet) {
            foreach ($sheet['students'] as $student) {
                $enrollmentId = (int) $student['enrollment_id'];

                $roster[$enrollmentId] ??= [
                    'ulid' => null,
                    'class_number' => $student['class_number'] ?? null,
                    'name' => (string) $student['name'],
                ];
            }
        }

        if ($roster === []) {
            return [];
        }

        $ulids = Enrollment::query()
            ->whereIn('id', array_keys($roster))
            ->pluck('ulid', 'id');

        foreach ($roster as $enrollmentId => $identity) {
            $roster[$enrollmentId]['ulid'] = $ulids[$enrollmentId] ?? null;
        }

        uasort(
            $roster,
            fn (array $left, array $right): int => ($left['class_number'] ?? PHP_INT_MAX) <=> ($right['class_number'] ?? PHP_INT_MAX),
        );

        return $roster;
    }

    public static function momentKey(int $periodId, SheetMomentKind $kind): string
    {
        return 'p'.$periodId.'-'.$kind->value;
    }
}
