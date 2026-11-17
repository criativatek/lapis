<?php

namespace App\Services\Reporting\ClassDocuments;

use App\Models\AcademicPeriod;
use App\Models\ClassificationScope;
use App\Models\Instrument;
use App\Models\SchoolClass;
use App\Models\SheetMomentKind;
use App\Services\Assessment\ClassResultsCalculator;
use App\Services\Assessment\Export\ClassSynopsisExport;
use App\Services\Assessment\Export\EvaluationSheetXlsxWriter;
use App\Services\Assessment\Export\LiveEvaluationSheet;
use App\Services\Assessment\InstrumentEligibility;
use App\Services\Assessment\WriteInstrumentResults;
use App\Support\Entitlements\Entitlements;
use RuntimeException;
use ZipArchive;

/**
 * The «Pacote de reunião»: everything that goes into the calculation of a class's classifications — and,
 * because assessment is continuous, everything behind the period asked for in the academic year — as
 * spreadsheets, in a single ZIP — so that a school network that fails does not leave a teacher at an
 * assessment meeting without the data in front of them.
 *
 * ONE SOURCE FOR THE BYTES. Each workbook comes out of the same code the document's own endpoint uses:
 * the Pauta from `LiveEvaluationSheet` + `EvaluationSheetXlsxWriter` («Exportar Excel»), the Quadro
 * Síntese from `ClassSynopsisExport` («Exportar Excel» of the Quadro), and each element's grid from
 * `WriteInstrumentResults` (the corrected grid of the instrument). This class selects, names and
 * archives; it writes no cell of its own.
 *
 * THE YEAR SO FAR. The package of period N carries the Pauta of every period up to N (the earlier ones at
 * their final moment, the one asked for last and at the moment asked for) and the elements of all of them.
 *
 * WHICH ELEMENTS. The eligible ones, by the engine's own rule (`ClassResultsCalculator::
 * eligibleInstrumentIdsUpTo`, which applies `InstrumentEligibility`) in EVERY period of the year up to the
 * one asked for, whatever the profile says about what feeds the accumulated. If the profile leaves a
 * period out of the accumulated, its elements are in the package and the LEIA-ME says they do not enter the
 * accumulated. A correction not yet concluded counts with what it has, so it enters, flagged as
 * provisional. The rest of the class's elements stay out and are counted by reason in the LEIA-ME:
 * cancelled, diagnostic, not set to count, still in preparation, or of a later period.
 *
 * WHAT GOES IN, BY PLAN. The Pauta and the Quadro Síntese belong to the `results` module, which is the
 * route's own gate. The element grids belong to `instruments`: without it they are left out, with the
 * reason `plan`, and it is not an error.
 *
 * NAMES NEVER REACH A FILE NAME. Entries are named after the class, the period, the day and the title of
 * the element (a slug), numbered so that two elements with the same title do not collide. The students are
 * in the spreadsheets, which is the point of them.
 *
 * NOTHING IS KEPT. The ZIP is written to a temporary file because `ZipArchive` needs one, and the caller
 * deletes it as soon as it has been sent.
 */
class MeetingPackage
{
    public const PAUTA = 'pauta';

    public const SYNOPSIS = 'quadro-sintese';

    public const ELEMENTS = 'elementos';

    public const ELEMENTS_MODULE = 'instruments';

    public function __construct(
        protected LiveEvaluationSheet $sheet,
        protected EvaluationSheetXlsxWriter $sheetWriter,
        protected ClassSynopsisExport $synopsis,
        protected ClassResultsCalculator $calculator,
        protected InstrumentEligibility $eligibility,
        protected WriteInstrumentResults $results,
        protected Entitlements $entitlements,
    ) {}

    /**
     * The files and the account of what is in and what is not. The caller has already authorised the
     * class and resolved the period (which is a period of the class's own year).
     */
    public function compose(SchoolClass $class, AcademicPeriod $period, SheetMomentKind $moment): MeetingPackageContents
    {
        $class->loadMissing(['subject', 'academicYear', 'profileVersion']);

        $momentSuffix = $moment === SheetMomentKind::Interim ? ' intercalar' : '';
        $classStem = str($class->label.' '.str_replace('/', ' ', (string) $class->academicYear->label))->slug()->limit(60, '')->value();

        // THE YEAR SO FAR. Assessment is continuous: the package of period N carries everything behind it in
        // the academic year, so the periods up to the one asked for, in order, the asked one last.
        $periods = AcademicPeriod::query()
            ->where('academic_year_id', $class->academic_year_id)
            ->where('sequence', '<=', $period->sequence)
            ->orderBy('sequence')
            ->get();

        $files = [];
        $included = [];
        $omitted = [];
        $students = 0;
        $sheets = 0;

        // 1. The Pauta of each period — the same document «Exportar Excel» writes. The earlier periods at
        // their final moment; the one asked for with the moment asked for.
        foreach ($periods as $index => $candidate) {
            $isRequested = (int) $candidate->getKey() === (int) $period->getKey();
            $kind = $isRequested ? $moment : SheetMomentKind::Final;
            $suffix = $isRequested ? $momentSuffix : '';
            $stem = str("{$class->label} {$candidate->label}{$suffix}")->slug()->limit(60, '')->value();

            $live = $this->sheet->for($class, $candidate, $kind);
            $files[sprintf('1-%02d-pauta-%s.xlsx', $index + 1, $stem)] = $this->sheetWriter->write($live['document']);
            $sheets++;

            if ($isRequested) {
                $students = $live['students'];
            }
        }

        $included[] = self::PAUTA;

        // 2. The Quadro Síntese of the class (already the year's).
        $files["2-quadro-sintese-{$classStem}.xlsx"] = $this->synopsis->build($class)['bytes'];
        $included[] = self::SYNOPSIS;

        // 3. The grid of each eligible element of the year up to this period.
        $elements = $this->elements($class, $period);

        if (! $this->entitlements->allows(self::ELEMENTS_MODULE)) {
            $omitted[] = [
                'document' => self::ELEMENTS,
                'code' => 'plan',
                'reason' => __('O plano da organização não inclui os elementos de avaliação.'),
            ];
            $elements['grids'] = [];
            $elements['outside_accumulated'] = [];
        }

        $provisional = 0;
        $names = [];

        foreach ($elements['grids'] as $number => $instrument) {
            $name = sprintf('3-%02d-%s-%s.xlsx', $number + 1, str((string) $instrument->title)->slug()->limit(40, '')->value() ?: 'elemento', $instrument->applied_on->toDateString());
            $names[$number] = $name;
            $files[$name] = $this->grid($instrument);

            if (! $instrument->status->isConcluded()) {
                $provisional++;
            }
        }

        if ($elements['grids'] !== []) {
            $included[] = self::ELEMENTS;
        }

        $archiveName = str("reuniao {$class->label} {$period->label}{$momentSuffix} ".now()->toDateString())
            ->slug()
            ->limit(80, '')
            ->value().'.zip';

        $count = count($elements['grids']);
        $outside = count($elements['outside_accumulated']);

        $files['LEIA-ME.txt'] = $this->readme($class, $period, $moment, array_values($periods->all()), $files, $elements, $names, $omitted, $students);

        return new MeetingPackageContents(
            $files, $included, $omitted, $students, $archiveName, $count, $provisional, $elements['omitted'],
            periodsIncluded: $periods->count(), sheetsIncluded: $sheets, elementsOutsideAccumulated: $outside,
        );
    }

    /**
     * Writes the archive to a temporary file and returns its path. The caller owns the file and deletes it
     * after sending; if archiving fails, nothing is left behind.
     *
     * @throws RuntimeException
     */
    public function archive(MeetingPackageContents $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lapis-reuniao-');

        if ($path === false) {
            throw new RuntimeException('Não foi possível criar o ficheiro temporário do pacote de reunião.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($path);

            throw new RuntimeException('Não foi possível abrir o ficheiro temporário do pacote de reunião.');
        }

        foreach ($contents->files as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }

        if ($zip->close() !== true) {
            @unlink($path);

            throw new RuntimeException('Não foi possível escrever o pacote de reunião.');
        }

        return $path;
    }

    /**
     * The class's elements split into the eligible ones of the year up to this period (chronological) and
     * the rest, counted by the reason they are not. ELIGIBLE is the engine's own rule, asked of the
     * calculator (`eligibleInstrumentIdsUpTo`): not cancelled, not diagnostic, set to count, not in
     * preparation. ONE query for the class's instruments, with their items and allocations loaded together,
     * so the grids that follow do not ask again.
     *
     * `outside_accumulated` lists the ones the profile leaves out of the accumulated figure (a period it
     * does not make contribute): they are in the package, because the teacher asked for the year so far, but
     * they do not enter the accumulated, and the LEIA-ME says so.
     *
     * @return array{grids: list<Instrument>, omitted: array<string, int>, outside_accumulated: list<int>}
     */
    protected function elements(SchoolClass $class, AcademicPeriod $period): array
    {
        $instruments = Instrument::query()
            ->where('class_id', $class->getKey())
            ->with(['items' => fn ($query) => $query->orderBy('sequence'), 'items.domainAllocations.domain', 'schoolClass'])
            ->orderBy('applied_on')
            ->orderBy('id')
            ->get();

        $hasProfile = $class->profileVersion !== null;

        $eligible = $hasProfile
            ? $this->calculator->eligibleInstrumentIdsUpTo($class, $period)->map(fn ($id): int => (int) $id)->all()
            : [];

        $accumulated = $hasProfile
            ? $this->calculator->contributingInstrumentIds($class, $period, ClassificationScope::Accumulated)->map(fn ($id): int => (int) $id)->all()
            : [];

        $grids = [];
        $omitted = [];
        $outside = [];

        foreach ($instruments as $instrument) {
            $id = (int) $instrument->getKey();

            if (in_array($id, $eligible, true)) {
                $grids[] = $instrument;

                if (! in_array($id, $accumulated, true)) {
                    $outside[] = $id;
                }

                continue;
            }

            $reason = match (true) {
                $instrument->status->value === 'cancelled' => 'cancelled',
                $this->eligibility->isDiagnostic($instrument) => 'diagnostic',
                ! $instrument->counts_toward_classification => 'not_counting',
                ! $instrument->status->entersCalculation() => 'draft',
                ! $hasProfile => 'no_profile',
                default => 'other_period',
            };

            $omitted[$reason] = ($omitted[$reason] ?? 0) + 1;
        }

        return ['grids' => $grids, 'omitted' => $omitted, 'outside_accumulated' => $outside];
    }

    /** The corrected grid of an element, as `instruments.grid.results` gives it. */
    protected function grid(Instrument $instrument): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lapis-results-');

        if ($path === false) {
            throw new RuntimeException('Não foi possível criar o ficheiro temporário de um elemento de avaliação.');
        }

        try {
            $this->results->write($instrument, $path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * The note that travels with the files: what they are, when they are from, what is missing and why,
     * and what to do with them. CRLF, UTF-8: it is opened in Notepad on the teacher's machine.
     *
     * @param  list<AcademicPeriod>  $periods
     * @param  array<string, string>  $files
     * @param  array{grids: list<Instrument>, omitted: array<string, int>, outside_accumulated: list<int>}  $elements
     * @param  array<int, string>  $gridNames  the archive name of each grid, by its position
     * @param  list<array{document: string, code: string, reason: string}>  $omitted
     */
    protected function readme(SchoolClass $class, AcademicPeriod $period, SheetMomentKind $moment, array $periods, array $files, array $elements, array $gridNames, array $omitted, int $students): string
    {
        $lines = [
            __('LAPISPRO — PACOTE DE REUNIÃO'),
            '',
            __('Turma: :class', ['class' => $class->label]),
            __('Disciplina: :subject', ['subject' => (string) $class->subject->name]),
            __('Ano letivo: :year', ['year' => (string) $class->academicYear->label]),
            __('Período e momento: :moment', ['moment' => $moment->momentLabel($period)]),
            __('A avaliação é contínua: o pacote traz também o que está para trás no ano letivo, até este período.'),
            __('Alunos na turma: :count', ['count' => $students]),
            __('Emitido a :moment', ['moment' => now()->timezone((string) config('app.timezone'))->format('d/m/Y, H:i')]),
            '',
            __('O QUE ESTÁ INCLUÍDO (folhas de Excel)'),
        ];

        $sheetNames = array_values(array_filter(array_keys($files), fn (string $name): bool => str_starts_with($name, '1-')));

        foreach ($periods as $index => $candidate) {
            $isRequested = (int) $candidate->getKey() === (int) $period->getKey();
            $lines[] = '- '.($sheetNames[$index] ?? '').': '.__('a Pauta de Avaliação do :period (:moment), com os resultados por domínio, o global, a proposta e o nível atribuído.', [
                'period' => (string) $candidate->label,
                'moment' => ($isRequested ? $moment : SheetMomentKind::Final)->momentLabel($candidate),
            ]);
        }

        foreach (array_keys($files) as $name) {
            if (str_starts_with($name, '2-')) {
                $lines[] = '- '.$name.': '.__('o Quadro Síntese da turma, do ano letivo, com os momentos, os domínios e os elementos de avaliação.');
            }
        }

        $outside = $elements['outside_accumulated'];

        if ($elements['grids'] !== []) {
            $lines[] = '';
            $lines[] = __('ELEMENTOS DE AVALIAÇÃO, por período (pontuações por questão e por aluno, e o resultado)');

            foreach ($periods as $candidate) {
                $ofPeriod = array_filter($elements['grids'], fn (Instrument $instrument): bool => (int) $instrument->academic_period_id === (int) $candidate->getKey());

                if ($ofPeriod === []) {
                    continue;
                }

                $lines[] = (string) $candidate->label;

                foreach ($ofPeriod as $number => $instrument) {
                    $lines[] = '- '.$gridNames[$number].': '.__('«:title», de :date.', ['title' => (string) $instrument->title, 'date' => $instrument->applied_on->format('d/m/Y')])
                        .($instrument->status->isConcluded() ? '' : ' '.__('PROVISÓRIO: a correção ainda não está concluída.'))
                        .(in_array((int) $instrument->getKey(), $outside, true) ? ' '.__('Não entra no acumulado, segundo o perfil de avaliação.') : '');
                }
            }
        }

        $reasons = [
            'cancelled' => __('Elementos de avaliação anulados'),
            'diagnostic' => __('Elementos de diagnóstico, que nunca contam para a classificação'),
            'not_counting' => __('Elementos marcados como não contando para a classificação'),
            'draft' => __('Elementos ainda em preparação'),
            'other_period' => __('Elementos de períodos posteriores'),
            'no_profile' => __('Elementos de uma turma sem perfil de avaliação'),
        ];

        $left = [];

        foreach ($omitted as $item) {
            $left[] = '- '.__('Elementos de avaliação').': '.$item['reason'];
        }

        foreach ($elements['omitted'] as $code => $count) {
            $left[] = '- '.($reasons[$code] ?? $code).': '.$count;
        }

        if ($left !== []) {
            $lines[] = '';
            $lines[] = __('O QUE FICOU DE FORA');
            array_push($lines, ...$left);
        }

        if ($outside !== []) {
            $lines[] = '';
            $lines[] = __('NOTA SOBRE O ACUMULADO');
            $lines[] = trim(__('Há elementos de períodos que o perfil de avaliação não faz contar para o acumulado. Vêm neste pacote, porque o pedido é o ano até aqui, mas não entram no acumulado: estão assinalados acima.'));
        }

        $lines = [
            ...$lines,
            '',
            __('COMO USAR'),
            __('- Isto é uma cópia do momento em que foi emitida. Não se atualiza: se os dados mudarem no Lapispro, estes ficheiros continuam a mostrar o que lá estava às horas acima.'),
            __('- Serve para consultar, não para lançar. Alterar um ficheiro de Excel não altera nada no Lapispro; se na reunião for decidida uma alteração, anote-a e registe-a na aplicação quando tiver ligação.'),
            __('- Os elementos de avaliação trazem o que contou no momento da emissão. Um marcado como PROVISÓRIO tem a correção por concluir: os valores podem ainda mudar.'),
            '',
            __('CUIDADO COM OS DADOS'),
            __('Estes ficheiros contêm dados de alunos identificáveis, na maioria menores. Guarde-os só no seu computador, não os envie por canais que não usaria para a pauta e apague-os quando a reunião terminar. Num computador partilhado, não os deixe na pasta de transferências.'),
        ];

        return implode("\r\n", $lines)."\r\n";
    }
}
