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
use App\Services\Assessment\DomainAppreciationDecisions;
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
 *
 * QUEM É CADA LINHA já não se decide aqui. `InovarStudentMatcher` responde a
 * essa pergunta por confiança — identificador, nome, ou nem um nem outro — e
 * este construtor limita-se a usar a resposta. A separação não é arrumação: o
 * que decide de quem é uma nota é a coisa mais perigosa deste fluxo, e tem de
 * ser legível e testável sozinha.
 */
class InovarExportPreviewBuilder
{
    public function __construct(
        protected BuildResultsProgression $progression,
        protected InovarCodeResolver $codes,
        protected ClassResultsCalculator $calculator,
        protected CoverageExplanation $coverage,
        protected InovarStudentMatcher $matcher,
        protected DomainAppreciationDecisions $decisions,
    ) {}

    /**
     * @param  array<int, int>  $resolutions  linha da grelha → matrícula que o professor escolheu
     * @return array<string, mixed>
     */
    public function build(
        SchoolClass $class,
        AcademicPeriod $period,
        InovarTemplate $template,
        ?InovarExportSource $source = null,
        array $resolutions = [],
    ): array {
        // The period as it stands, unless a kept moment was handed over. The
        // grid, the matching, the filling and the fidelity checks are identical
        // either way — only where the mentions come from differs (§9, §10).
        $source ??= new CurrentPeriodResultsSource(
            $class, $period, $this->progression, $this->calculator, $this->coverage, $this->codes, $this->decisions,
        );

        $enrollments = $this->enrollments($class);

        $domains = $this->domainRows($class, $template);
        // QUEM É CADA LINHA é decidido por um serviço próprio, por confiança e
        // não por uma única chave — ver InovarStudentMatcher. O construtor
        // deixa de saber como se reconhece uma pessoa; sabe apenas o que fazer
        // com o que lhe respondem.
        $students = $this->matcher->match($template, $enrollments, $resolutions);
        $values = $this->valueRows($source, $students, $domains);

        $blocking = $this->blockingErrors($students, $domains, $source);
        $warnings = $this->warnings($students, $values, $enrollments);

        return [
            'students' => $students,
            'domains' => $domains,
            'values' => $values,
            // Os candidatos que o professor pode escolher, para as linhas que
            // lhe são devolvidas por decidir. Uma lista só, para a página
            // inteira: as linhas apontam para ela por id.
            'candidates' => $this->candidates($enrollments),
            'source' => [
                'label' => $source->label(),
                'reference_label' => $source->referenceLabel(),
            ],
            'summary' => [
                'matched_students' => count(array_filter($students, fn (array $row): bool => $row['matched'])),
                'unmatched_students' => count(array_filter($students, fn (array $row): bool => ! $row['matched'])),
                'students_needing_teacher' => count(array_filter($students, fn (array $row): bool => $row['needs_teacher'])),
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
     * Os alunos desta turma, como uma lista para escolher.
     *
     * O N.º de processo viaja porque é o que distingue dois homónimos numa
     * lista de escolha — e porque, quando o Lapispro não tem nenhum, dizê-lo é
     * a informação de que o professor precisa para perceber a linha.
     *
     * @param  Collection<int, Enrollment>  $enrollments
     * @return list<array<string, mixed>>
     */
    protected function candidates(Collection $enrollments): array
    {
        return array_values($enrollments->map(fn (Enrollment $enrollment): array => [
            'enrollment_id' => (int) $enrollment->getKey(),
            'class_number' => $enrollment->class_number,
            'name' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)',
            'process_number' => $enrollment->student->processNumber(),
        ])->all());
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

    // Quem é cada linha da grelha mudou de casa: vive em InovarStudentMatcher,
    // que responde por confiança em vez de por uma única chave. O N.º de
    // processo deixou de ser requisito — passou a ser um sinal forte entre
    // outros —, e por isso também desapareceu daqui a lista de alunos «sem N.º
    // de processo»: já não é uma coisa que impeça uma exportação (§29).

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
                    // Se aquela menção é a DECISÃO do professor sobre o domínio
                    // ou a leitura do Lapispro. Não muda o que é escrito — muda
                    // o que o ecrã de preparação diz sobre a célula.
                    'decided_by_teacher' => (bool) ($cell['decided_by_teacher'] ?? false),
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
     * O QUE IMPEDE A EXPORTAÇÃO DE AVANÇAR — e apenas isso.
     *
     * TRÊS COISAS, e nenhuma delas é «falta informação». Uma coluna que não
     * corresponde a domínio nenhum, uma escala sem correspondência INOVAR, e um
     * ficheiro com o mesmo N.º de processo repetido: erros que nenhuma decisão
     * do professor resolve, e que fariam escrever no sítio errado.
     *
     * UMA LINHA POR IDENTIFICAR NÃO ESTÁ AQUI, e é uma mudança deliberada. Uma
     * correspondência provável ou ambígua é uma PERGUNTA ao professor, e a
     * exportação espera pela resposta dela noutro sítio (`needsTeacher`) —
     * chamar-lhe erro seria dizer que alguém se enganou quando ninguém se
     * enganou. E uma linha sem correspondência nenhuma não impede nada: fica em
     * branco, que é o resultado correto para um aluno que não é desta turma.
     *
     * O N.º DE PROCESSO DEIXOU DE SER REQUISITO. Uma turma escrita à mão não
     * tem nenhum, e bloquear a exportação inteira por causa disso era exigir
     * uma informação que a escola já tem no ficheiro que acabou de carregar
     * (§29).
     *
     * @param  list<array<string, mixed>>  $students
     * @param  list<array<string, mixed>>  $domains
     * @return list<string>
     */
    protected function blockingErrors(array $students, array $domains, InovarExportSource $source): array
    {
        $errors = [];

        foreach ($students as $student) {
            if (($student['blocking'] ?? false) !== true) {
                continue;
            }

            foreach ($student['reasons'] as $reason) {
                $errors[] = $reason;
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
     * @param  Collection<int, Enrollment>  $enrollments
     * @return list<string>
     */
    protected function warnings(array $students, array $values, Collection $enrollments): array
    {
        $warnings = [];

        // A PERGUNTA POR RESPONDER, dita primeiro: é a única coisa aqui que
        // ainda espera pelo professor, e a que decide se a exportação avança.
        $pending = count(array_filter($students, fn (array $row): bool => $row['needs_teacher']));

        if ($pending > 0) {
            $warnings[] = $pending === 1
                ? '1 linha da grelha ainda não tem o aluno confirmado.'
                : "{$pending} linhas da grelha ainda não têm o aluno confirmado.";
        }

        // Alunos DESTA TURMA que nenhuma linha da grelha reclamou. Não é um
        // erro — a grelha da escola pode ser de outra disciplina ou estar
        // incompleta —, mas é a informação que um professor quer ver antes de
        // exportar, porque significa que esse aluno não leva nota nenhuma.
        $claimed = array_filter(array_column($students, 'enrollment_id'), fn (?int $id): bool => $id !== null);
        $missing = [];

        foreach ($enrollments as $enrollment) {
            if (! in_array((int) $enrollment->getKey(), $claimed, true)) {
                $missing[] = optional($enrollment->student->identity)->display_name ?? '(sem identidade)';
            }
        }

        if ($missing !== []) {
            $warnings[] = count($missing) === 1
                ? "{$missing[0]} não tem linha nesta grelha e por isso não leva nenhuma menção."
                : count($missing).' alunos não têm linha nesta grelha e por isso não levam menção nenhuma: '.implode(', ', $missing).'.';
        }

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
}
