<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\SchoolClass;
use App\Models\SheetMomentKind;
use App\Models\User;
use App\Services\Assessment\BuildEvaluationSheet;
use App\Services\Assessment\CaptureEvaluationSheet;
use App\Services\Assessment\EvaluationSheetReadiness;
use App\Support\Assessment\DecisionScale;
use App\Support\Assessment\DomainColorPalette;
use App\Support\Assessment\SheetAddressing;
use App\Support\Entitlements\Entitlements;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pautas de Avaliação — one view, not three. Quantitativo, apreciação
 * qualitativa por domínio e classificação (sugerida vs. decidida) chegam todos
 * ao mesmo tempo; o professor só pode OCULTAR grupos no ecrã, nunca alterar o
 * que o servidor calculou.
 *
 * E É AQUI QUE O PROFESSOR DECIDE. A pauta é o ecrã onde toda a informação
 * avaliativa já está reunida, e obrigar a sair dela para atribuir um nível era
 * mandar decidir noutro sítio a partir do que se viu neste. A decisão continua
 * a ser escrita pelo MESMO caminho canónico — `classifications.decide`, o mesmo
 * serviço, a mesma validação, o mesmo bloqueio de linha e o mesmo rasto de
 * auditoria que o ecrã de Classificações usa. Esta página não guarda
 * classificações: aponta para quem as guarda (§3.3).
 */
class EvaluationSheetController extends Controller
{
    public function __construct(
        protected BuildEvaluationSheet $builder,
        protected CaptureEvaluationSheet $capture,
        protected EvaluationSheetReadiness $readiness,
    ) {}

    public function index(): Response
    {
        $classes = SchoolClass::query()
            ->whereHas('teachers', fn ($query) => $query->whereKey($this->user()->getKey()))
            ->with(['subject', 'academicYear'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (SchoolClass $class) => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'academic_year' => $class->academicYear->label,
                'has_profile' => $class->assessment_profile_version_id !== null,
            ]);

        return Inertia::render('evaluation-sheets/Index', ['classes' => $classes]);
    }

    public function show(Request $request, SchoolClass $class, ?string $period = null): Response
    {
        Gate::authorize('view', $class);

        $periods = AcademicPeriod::where('academic_year_id', $class->academic_year_id)
            ->orderBy('sequence')
            ->get();

        $selected = $period !== null
            ? $periods->firstWhere('ulid', $period)
            : $periods->first();

        // QUAL DOS DOIS MOMENTOS ESTRUTURAIS. Uma unidade temporal tem um
        // momento intercalar e um momento final, e o topo da pauta oferece os
        // dois; o que muda entre eles não é um número — é o que se está a
        // preparar. Sem indicação nenhuma, é o final: é o momento por omissão,
        // e é o que qualquer ligação antiga para esta pauta continua a abrir.
        $moment = SheetMomentKind::fromRequest($request->query('momento') === null ? null : (string) $request->query('momento'));

        $sheet = $selected !== null
            ? $this->builder->for($class, $selected)
            : null;

        $canExportToInovar = app(Entitlements::class)->allows('inovar_export');

        // «Preparar fecho» — computed over the very same read model the screen
        // shows, BEFORE presentation decorates it. Nothing is recalculated: the
        // checklist is a reading of the sheet, plus a handful of flat lookups.
        // Null while there is nothing to prepare (no period, or nobody on the
        // sheet), which is exactly when the page shows its empty state instead.
        // A non-null sheet already implies a selected period — it is only ever
        // built from one.
        $readiness = $sheet !== null && $sheet['students'] !== []
            ? $this->readiness->for($class, $selected, $sheet, $canExportToInovar, $moment)
            : null;

        if ($sheet !== null) {
            // BuildEvaluationSheet's domain rows carry no colour of their own —
            // it is an export-neutral read model and colour is presentation.
            // Resolved through the same seam CaptureEvaluationSheet uses, so
            // the kept photograph can never be painted differently from the
            // screen it was taken of.
            $sheet['domains'] = DomainColorPalette::decorate($sheet['domains']);

            // The address each decision is written to. Deliberately outside the
            // read model, so a kept pauta never carries identifiers it has no
            // use for — see SheetAddressing. Domains carry one now too: each
            // domain's appreciation is itself a decision the teacher may write.
            $sheet['domains'] = SheetAddressing::decorateDomains($sheet['domains']);
            $sheet['students'] = SheetAddressing::decorate($sheet['students'], $class);
        }

        // What the teacher is being asked for, said in the terms of the scale
        // itself — the same payload Resultados and Classificações already read,
        // so three screens cannot offer three different decisions (§3).
        $scale = $class->profileVersion?->scale()->with('levels')->first();

        return Inertia::render('evaluation-sheets/Show', [
            'schoolClass' => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'academic_year' => $class->academicYear->label,
                'has_profile' => $class->assessment_profile_version_id !== null,
                'scale_name' => $scale?->name,
            ],
            'decision' => DecisionScale::for($scale)->toPayload(),
            // A ESCALA POR ORDEM, para a apreciação de cada célula ser pintada
            // pela POSIÇÃO do nível nela e nunca pelo número que ele tem (§24).
            // O ecrã pinta; a fotografia guardada não recebe isto e fica sem cor
            // de nível, porque abri-la não pode ir buscar nada ao presente (§15).
            'scaleBands' => $scale === null ? [] : $scale->levels
                ->sortBy('sequence')
                ->map(fn ($level): array => [
                    'code' => (string) $level->code,
                    'label' => (string) $level->label,
                    'sequence' => (int) $level->sequence,
                    'is_negative' => (bool) $level->is_negative,
                ])->values()->all(),
            // OS MOMENTOS ESTRUTURAIS DO ANO, e apenas eles: para cada unidade
            // temporal configurada, o intercalar e o final, por essa ordem. Uma
            // fotografia guardada não é um momento estrutural e não aparece
            // aqui — vive no Histórico, que é onde um registo vive (§20).
            'periods' => $periods->flatMap(fn (AcademicPeriod $academicPeriod) => array_map(
                fn (SheetMomentKind $kind): array => [
                    'ulid' => $academicPeriod->ulid,
                    'moment' => $kind->value,
                    'label' => $kind->tabLabel($academicPeriod),
                    // A palavra da escola para a sua própria unidade de tempo.
                    // Nada aqui escreve «semestre» (§6).
                    'kind_label' => $academicPeriod->kind->label(),
                    'moment_label' => $kind->momentLabel($academicPeriod),
                    'selected' => $selected !== null
                        && $academicPeriod->id === $selected->id
                        && $kind === $moment,
                ],
                SheetMomentKind::inOrder(),
            ))->values(),
            'moment' => $moment->value,
            'sheet' => $sheet,
            // What the «Guardar esta pauta» form opens with. A SUGGESTION: both
            // fields are editable, the title is built from the period's own
            // configuration (never a hardcoded «semestre»), and the reference
            // date defaults to today clamped into the period, because a date
            // outside it would be refused the moment the teacher pressed save.
            'saveDefaults' => $selected === null ? null : [
                'period_ulid' => $selected->ulid,
                // O título abre a dizer QUE MOMENTO se está a guardar —
                // «Intercalar 1.º Semestre», «1.º Semestre» —, construído a
                // partir da configuração do próprio período. Continua a ser
                // editável: é uma sugestão.
                'moment_label' => $this->capture->suggestedLabel($selected, $moment),
                // OS TÍTULOS ESTRUTURAIS DO ANO INTEIRO, para o campo deixar de
                // ser uma caixa em branco e passar a ser uma escolha (§20). O
                // valor por omissão acima é um destes, e é por isso que a lista
                // abre no momento em que o professor está (§21) sem que nada
                // tenha de comparar textos para o descobrir.
                'moment_titles' => $this->capture->structuralTitles($periods),
                'effective_at' => $this->capture->defaultEffectiveDate($selected)->toDateString(),
                'starts_on' => $selected->starts_on->toDateString(),
                'ends_on' => $selected->ends_on->toDateString(),
            ],
            // PRESENTATION ONLY. Hiding the action is not access control — the
            // route itself sits behind `module:inovar_export` and refuses on
            // the server. This just spares a teacher a door that opens onto a
            // 403 (§8.2).
            'canExportToInovar' => $canExportToInovar,
            'readiness' => $readiness,
        ]);
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
