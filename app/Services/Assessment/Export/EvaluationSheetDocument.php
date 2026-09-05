<?php

namespace App\Services\Assessment\Export;

use App\Models\AcademicPeriod;
use App\Models\ClassificationScope;
use App\Models\EvaluationSheetExport;
use App\Models\SchoolClass;
use Illuminate\Support\Str;

/**
 * Uma pauta pronta a escrever num ficheiro — a de hoje ou a de dezembro.
 *
 * DUAS ORIGENS, UMA FORMA. O CSV e o Excel da pauta atual e os da pauta
 * guardada têm de ter exatamente as mesmas colunas: um professor que junte os
 * dois num mesmo livro está a comparar dois momentos, e colunas que não
 * alinhassem tornariam essa comparação num exercício de adivinhação. O que os
 * separa é a ORIGEM dos números, e é aí que a separação tem de ser absoluta.
 *
 * `fromSnapshot()` NÃO TOCA EM NADA VIVO. Não constrói a pauta, não lê
 * classificações, não consulta a escala de hoje para reescrever uma menção, não
 * repinta domínios com a paleta atual. Lê o payload congelado e mais nada — é
 * por isso que este objeto recebe um `EvaluationSheetExport` e não tem forma
 * nenhuma de chegar a `BuildEvaluationSheet`. Um ficheiro de um momento passado
 * que incorporasse uma alteração posterior não seria um arquivo: seria uma
 * afirmação falsa sobre o passado (§16).
 *
 * `fromLiveSheet()` é o oposto declarado: o estado de agora, dito como tal.
 */
final readonly class EvaluationSheetDocument
{
    /**
     * @param  list<array<string, mixed>>  $domains
     * @param  list<array<string, mixed>>  $students
     * @param  list<string>  $warnings
     */
    private function __construct(
        public string $classLabel,
        public string $subject,
        public string $academicYear,
        public string $periodLabel,
        public string $periodKindLabel,
        public string $momentLabel,
        public ?string $effectiveAt,
        /** Quando a fotografia foi tirada. Null numa pauta viva, que não é uma. */
        public ?string $keptAt,
        public ?string $authorName,
        public string $scopeLabel,
        public array $domains,
        public array $students,
        public array $warnings,
        /** Se isto é um momento guardado, e não o estado de hoje. */
        public bool $isHistorical,
    ) {}

    /**
     * O documento tal como foi guardado — lido do payload, e só dele.
     */
    public static function fromSnapshot(EvaluationSheetExport $export): self
    {
        /** @var array<string, mixed> $payload */
        $payload = $export->payload;
        /** @var array{label?: string, subject?: string, academic_year?: string} $class */
        $class = $payload['class'] ?? [];
        /** @var array{label?: string, kind_label?: string} $period */
        $period = $payload['period'] ?? [];
        /** @var array{label?: string, effective_at?: string} $moment */
        $moment = $payload['moment'] ?? [];
        /** @var array{name?: string} $author */
        $author = $payload['author'] ?? [];
        /** @var array<array-key, string> $warnings */
        $warnings = $payload['warnings'] ?? [];
        /** @var array<array-key, array<string, mixed>> $domains */
        $domains = $payload['domains'] ?? [];
        /** @var array<array-key, array<string, mixed>> $students */
        $students = $payload['students'] ?? [];

        return new self(
            classLabel: (string) ($class['label'] ?? '—'),
            subject: (string) ($class['subject'] ?? '—'),
            academicYear: (string) ($class['academic_year'] ?? '—'),
            periodLabel: (string) ($period['label'] ?? '—'),
            periodKindLabel: (string) ($period['kind_label'] ?? '—'),
            momentLabel: (string) ($moment['label'] ?? $export->moment_label),
            effectiveAt: isset($moment['effective_at']) ? (string) $moment['effective_at'] : $export->effective_at?->toDateString(),
            // A ÚNICA COISA LIDA FORA DO PAYLOAD, e deliberadamente: a coluna
            // é imutável por construção e o payload nunca a levou. Continua a
            // ser um facto sobre o passado, não sobre hoje.
            keptAt: $export->exported_at->toIso8601String(),
            authorName: isset($author['name']) ? (string) $author['name'] : null,
            scopeLabel: $export->scope->label(),
            domains: array_values($domains),
            students: array_values($students),
            warnings: array_values($warnings),
            isHistorical: true,
        );
    }

    /**
     * A pauta como está agora.
     *
     * @param  array{domains: list<array<string, mixed>>, students: list<array<string, mixed>>}  $sheet
     */
    public static function fromLiveSheet(
        SchoolClass $class,
        AcademicPeriod $period,
        array $sheet,
        ClassificationScope $scope,
    ): self {
        return new self(
            classLabel: (string) $class->label,
            subject: (string) $class->subject->name,
            academicYear: (string) $class->academicYear->label,
            periodLabel: (string) $period->label,
            periodKindLabel: $period->kind->label(),
            // A pauta viva não é um momento guardado e não tem título próprio:
            // chama-se pelo período que está a ser visto.
            momentLabel: $period->kind->label().' — '.$period->label,
            effectiveAt: null,
            keptAt: null,
            authorName: null,
            scopeLabel: $scope->label(),
            domains: $sheet['domains'],
            students: $sheet['students'],
            warnings: [],
            isHistorical: false,
        );
    }

    /**
     * Se alguém, nesta pauta, se pronunciou sobre si próprio.
     *
     * Uma pauta guardada antes de a autoavaliação viajar no payload responde
     * que não — e o ficheiro sai sem as colunas, exatamente como o ecrã de onde
     * a fotografia foi tirada saía.
     */
    public function hasSelfAssessment(): bool
    {
        foreach ($this->students as $student) {
            if (($student['self_assessment'] ?? null) !== null) {
                return true;
            }

            /** @var array<array-key, array<string, mixed>> $domains */
            $domains = $student['domains'] ?? [];

            foreach ($domains as $domain) {
                if (($domain['self_assessment'] ?? null) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * O nome do ficheiro, legível por uma pessoa e seguro para um sistema de
     * ficheiros: «Pauta_7A_Portugues_Momento-intercalar_2026-12-15».
     *
     * Acentos convertidos e não removidos («Português» → «Portugues», nunca
     * «Portugus»), tudo o resto reduzido a underscores, e sem extremos soltos.
     * O que identifica o momento vai no nome PRECISAMENTE porque um ficheiro
     * descarregado deixa de ter contexto no segundo seguinte.
     */
    public function fileStem(): string
    {
        $parts = [
            'Pauta',
            $this->classLabel,
            $this->subject,
            $this->momentLabel,
            $this->effectiveAt ?? '',
        ];

        $clean = [];

        foreach ($parts as $part) {
            $value = Str::of($part)->ascii()->replaceMatches('/[^A-Za-z0-9]+/', '-')->trim('-')->value();

            if ($value !== '') {
                $clean[] = $value;
            }
        }

        $stem = implode('_', $clean);

        // Nomes de ficheiro têm limites reais, e um título de momento pode ter
        // 200 caracteres. Cortar é melhor do que um download que falha.
        return $stem === '' ? 'Pauta' : Str::limit($stem, 120, '');
    }
}
