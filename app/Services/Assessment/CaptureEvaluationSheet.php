<?php

namespace App\Services\Assessment;

use App\Domain\Export\GeneratedExportFile;
use App\Models\AcademicPeriod;
use App\Models\ClassificationScope;
use App\Models\EvaluationSheetExport;
use App\Models\SchoolClass;
use App\Models\SheetMomentKind;
use App\Models\User;
use App\Support\Assessment\CoverageWording;
use App\Support\Assessment\DomainColorPalette;
use App\Support\Assessment\EvaluationSheetException;
use App\Support\Hashing\CanonicalPayload;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Takes the photograph of a Pauta de Avaliação.
 *
 * NOTHING IS CALCULATED HERE. The numbers come from BuildEvaluationSheet — the
 * very same read model, the very same figures the teacher was looking at when
 * they pressed the button. What this adds is the act of keeping it, and the
 * historical identity that keeps it readable after the live configuration has
 * moved on.
 *
 * THE PAYLOAD HAS TO BE ENOUGH ON ITS OWN. Opening a snapshot must never join
 * anything live: not the class, not the period, not the domains, not the
 * colours. That is why the labels travel as literal copies (§15). A school that
 * renames «1.º Semestre» to «1.º Período» in February has not changed what
 * December's pauta said, and the record must go on saying it.
 *
 * The colour travels for the same reason. It is presentation, but it is the
 * presentation that was on screen; a snapshot repainted by today's palette is
 * a different document from the one the teacher kept.
 */
class CaptureEvaluationSheet
{
    /**
     * A forma que este escritor produz. Só cresce.
     *
     * v1 → v2: o momento passou a dizer QUAL DOS DOIS momentos estruturais é —
     * intercalar ou final. Uma fotografia v1 não o diz, e não lhe é atribuído
     * nenhum: era tirada de um ecrã onde a distinção não existia, e decidir
     * agora que foi «final» seria escrever no passado uma afirmação que
     * ninguém fez. Quem lê mostra o que lá está, e cala o que lá não está.
     *
     * As decisões por domínio entraram no mesmo momento, mas dentro de
     * `students[].domains[]`, onde a sua ausência já significa exatamente o que
     * significa: não houve nenhuma.
     */
    public const CURRENT_VERSION = 2;

    public function __construct(
        protected BuildEvaluationSheet $builder,
    ) {}

    /**
     * ONE PLACE TAKES THE PHOTOGRAPH, whatever the occasion.
     *
     * Pressing «Guardar esta pauta» and exporting a grid to INOVAR are the same
     * act seen twice: both freeze what was on screen at a moment, and both have
     * to go on saying it afterwards. So the export does not build a second
     * payload — it calls this, and adds three things:
     *
     *  - `$adapter` — WHAT produced the record («snapshot», «inovar»);
     *  - `$extraWarnings` — what was incomplete ABOUT THE EXPORT itself, in the
     *    same sentences the rest of the payload speaks, so `warning_count` goes
     *    on being exactly `count($payload['warnings'])` and history has nothing
     *    to reconcile;
     *  - `$file` — where the produced file lives and the checksum of its bytes.
     *
     * @param  list<string>  $extraWarnings
     */
    public function capture(
        SchoolClass $class,
        AcademicPeriod $period,
        ClassificationScope $scope,
        string $momentLabel,
        Carbon $effectiveAt,
        User $author,
        string $adapter = 'snapshot',
        array $extraWarnings = [],
        ?GeneratedExportFile $file = null,
        SheetMomentKind $moment = SheetMomentKind::Final,
    ): EvaluationSheetExport {
        $this->guardTheDate($class, $period, $effectiveAt);

        // ONE build, exactly as the screen does it, with the colour resolved
        // through the single seam both sides share.
        $sheet = $this->builder->for($class, $period, $scope);
        $domains = DomainColorPalette::decorate($sheet['domains']);
        $students = $sheet['students'];

        // The sheet's own incompleteness first, then whatever the occasion
        // added. Deduplicated because the two can land on the same sentence and
        // a teacher reading the same line twice learns nothing the second time.
        $warnings = array_values(array_unique([
            ...$this->warnings($domains, $students),
            ...$extraWarnings,
        ]));

        $payload = [
            'version' => self::CURRENT_VERSION,
            'scope' => $scope->value,
            'class' => [
                'label' => (string) $class->label,
                'subject' => (string) $class->subject->name,
                'academic_year' => (string) $class->academicYear->label,
            ],
            // CRITICAL. Read once, here, and never again: the history screen
            // must not go looking up what this period is called today.
            'period' => [
                'label' => (string) $period->label,
                'kind_label' => $period->kind->label(),
            ],
            'moment' => [
                'label' => $momentLabel,
                'effective_at' => $effectiveAt->toDateString(),
                // QUAL DOS DOIS momentos estruturais. O título é editável e
                // pode acabar a dizer qualquer coisa; isto é a identidade
                // pedagógica do momento, e não muda por alguém reescrever o
                // título (§21).
                'kind' => $moment->value,
            ],
            'author' => ['name' => (string) $author->name],
            'domains' => $domains,
            'students' => $students,
            'warnings' => $warnings,
        ];

        return EvaluationSheetExport::create([
            'class_id' => $class->id,
            'academic_period_id' => $period->id,
            'scope' => $scope,
            // WHAT produced the record. «snapshot» when nothing was exported
            // anywhere — the default, and the act of simply keeping it.
            'adapter' => $adapter,
            'moment_label' => $momentLabel,
            'effective_at' => $effectiveAt->toDateString(),
            'payload' => $payload,
            'payload_hash' => CanonicalPayload::hash($payload),
            'warning_count' => count($warnings),
            'exported_with_warnings' => $warnings !== [],
            // The column is NOT NULL with a default; a record without a file
            // still names the disk it would have used.
            'file_disk' => $file->disk ?? 'local',
            'file_path' => $file?->path,
            'file_checksum' => $file?->checksum,
            'original_extension' => $file?->extension,
            'exported_by' => $author->id,
            'exported_at' => now(),
        ]);
    }

    /**
     * The label to offer, which the teacher then confirms or replaces.
     *
     * Built from the period's OWN configuration — «Semestre — 1.º Semestre»,
     * «Momento intercalar do 2.º Período» — so nothing anywhere hardcodes what
     * a school calls its own units of time (§6).
     *
     * WHICH OF THE TWO STRUCTURAL MOMENTS is part of the label, because it is
     * part of what is being kept: a photograph taken halfway through a period
     * and one taken to close it are different documents, and a history where
     * both read «Semestre — 1.º Semestre» would make them indistinguishable
     * months later. The default stays the closing moment, which is what every
     * pauta kept until now was.
     *
     * A SUGGESTION AND NOTHING MORE. It arrives in an editable field and is
     * only saved once somebody presses the button.
     */
    public function suggestedLabel(AcademicPeriod $period, SheetMomentKind $moment = SheetMomentKind::Final): string
    {
        return $moment->momentLabel($period);
    }

    /** What the teacher typed, tidied — never silently replaced. */
    public function labelFor(?string $given, AcademicPeriod $period, SheetMomentKind $moment = SheetMomentKind::Final): string
    {
        $given = $given === null ? '' : trim(preg_replace('/\s+/u', ' ', $given) ?? '');

        return $given !== '' ? $given : $this->suggestedLabel($period, $moment);
    }

    /**
     * Today when today is inside the period, otherwise the nearest edge of it.
     *
     * A period already finished gets its last day — which is the ordinary case
     * for a grid that closes it, and the reason this is not simply «today». One
     * that has not started yet gets its first, and that one is then refused on
     * save, because a photograph of a moment that has not arrived is a
     * photograph of nothing; the refusal says so in words rather than a screen
     * guessing a date belonging to another period.
     *
     * Nothing is inferred from the period's NAME — only from the dates it
     * actually carries (§6). It lives here, beside the guard that enforces the
     * same boundaries, so the suggestion and the refusal can never drift apart.
     */
    public function defaultEffectiveDate(AcademicPeriod $period): CarbonInterface
    {
        $today = now()->startOfDay();

        if ($today->lessThan($period->starts_on->copy()->startOfDay())) {
            return $period->starts_on->copy()->startOfDay();
        }

        if ($today->greaterThan($period->ends_on->copy()->startOfDay())) {
            return $period->ends_on->copy()->startOfDay();
        }

        return $today;
    }

    /**
     * The date has to be a date this period actually contains.
     *
     * READ FROM THE PERIOD'S OWN starts_on/ends_on, never inferred from its
     * name — the same rule CaptureInterimAssessment applies, for the same
     * reason (§6).
     */
    protected function guardTheDate(SchoolClass $class, AcademicPeriod $period, Carbon $effectiveAt): void
    {
        if ((int) $period->academic_year_id !== (int) $class->academic_year_id) {
            throw EvaluationSheetException::periodOutsideClass();
        }

        $date = $effectiveAt->copy()->startOfDay();

        if ($date->lessThan($period->starts_on->copy()->startOfDay())) {
            throw EvaluationSheetException::beforePeriodStart($period);
        }

        if ($date->greaterThan($period->ends_on->copy()->endOfDay())) {
            throw EvaluationSheetException::afterPeriodEnd($period);
        }

        // A photograph of a moment that has not arrived would be a photograph
        // of nothing — and would look identical to today's, which is worse.
        if ($date->greaterThan(now()->endOfDay())) {
            throw EvaluationSheetException::inTheFuture();
        }
    }

    /**
     * What was incomplete at the moment the photograph was taken, in words.
     *
     * SENTENCES, NOT RAW PAYLOAD. These are read months later by a teacher and
     * possibly by somebody who was not in the room; a dump of flags and ids
     * would be evidence of nothing. Every line is derived from what the read
     * model already states — no new judgement is made here, and in particular
     * a missing element is never reported as a zero (§13.3).
     *
     * @param  list<array<string, mixed>>  $domains
     * @param  list<array<string, mixed>>  $students
     * @return list<string>
     */
    protected function warnings(array $domains, array $students): array
    {
        $warnings = [];

        if ($domains === []) {
            $warnings[] = 'O perfil de avaliação desta turma não tem domínios definidos.';
        }

        if ($students === []) {
            $warnings[] = 'Não há alunos com resultados nesta pauta.';

            return $warnings;
        }

        foreach ($students as $student) {
            $name = (string) $student['name'];
            $overall = $student['overall'];
            $coverage = $student['coverage'];

            // OS TRÊS ESTADOS, DITOS PELO MESMO SÍTIO que «Preparar fecho» e o
            // ⚠ do ecrã usam (§17). A distinção que interessa é entre não ter
            // havido avaliação nenhuma e ter havido avaliação incompleta: uma
            // fotografia que dissesse a segunda coisa sobre a primeira estaria
            // a afirmar, para sempre, que houve uma avaliação que não houve.
            if (($coverage['no_elements'] ?? false) === true) {
                $warnings[] = "{$name}: sem elementos avaliados — a pauta não mostra qualquer resultado.";
            } elseif (($overall['has_coverage_warning'] ?? false) === true) {
                $state = CoverageWording::state(true, $overall['normalized_value'] !== null);

                if ($state === CoverageWording::PARTIAL) {
                    $warnings[] = "{$name}: ".lcfirst(CoverageWording::partial('overall'));
                } else {
                    $warnings[] = "{$name}: ".lcfirst(CoverageWording::none('overall'));
                }
            }

            $classification = $student['classification'];

            if ($classification === null) {
                $warnings[] = "{$name}: sem classificação registada.";

                continue;
            }

            // A proposal is not a decision (§3.3): a row that only carries the
            // system's suggestion is still a decision the teacher has not made.
            if ($classification['final_value'] === null && $classification['final_scale_level_id'] === null) {
                $warnings[] = "{$name}: classificação ainda não decidida pelo professor.";
            }
        }

        return $warnings;
    }
}
