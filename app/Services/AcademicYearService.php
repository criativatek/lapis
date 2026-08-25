<?php

namespace App\Services;

use App\Models\AcademicCalendarException;
use App\Models\AcademicCalendarExceptionSource;
use App\Models\AcademicPeriod;
use App\Models\AcademicPeriodStatus;
use App\Models\AcademicYear;
use App\Models\CalculationSnapshot;
use App\Models\Classification;
use App\Models\EvidenceRecord;
use App\Models\Instrument;
use App\Models\InterimAssessment;
use App\Models\Intervention;
use App\Models\ProfileVersionPeriod;
use App\Models\Report;
use App\Models\SelfAssessment;
use App\Support\AcademicYears\AcademicYearValidationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;

/**
 * Creates and updates an academic year together with its periods — and, desde a
 * Fase 5.4, com as suas exceções letivas — in one transaction (§24.2). A
 * half-written year with missing periods is never left behind if anything fails
 * partway, e uma remoção de período recusada não deixa lá as exceções que vinham
 * no mesmo pedido.
 */
class AcademicYearService
{
    /**
     * Every table with a RESTRICT foreign key to academic_periods.id, kept in
     * one place so "does this period have dependents" has exactly one list to
     * update if a tenth dependent table is ever added — the exact risk this
     * class's own history already flagged once, before periods ever carried
     * results (see the git history of syncPeriods() below).
     *
     * @var list<class-string>
     */
    private const DEPENDENT_MODELS = [
        CalculationSnapshot::class,
        Classification::class,
        ProfileVersionPeriod::class,
        Instrument::class,
        EvidenceRecord::class,
        SelfAssessment::class,
        Intervention::class,
        InterimAssessment::class,
        Report::class,
    ];

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $periods
     * @param  list<array<string, mixed>>|null  $exceptions  Null means «this
     *                                                       request did not speak about exceptions at all», not «remove
     *                                                       them» — see syncExceptions() below.
     */
    public function create(array $attributes, array $periods, ?array $exceptions = null): AcademicYear
    {
        return DB::transaction(function () use ($attributes, $periods, $exceptions): AcademicYear {
            $year = AcademicYear::create($attributes);
            $this->syncPeriods($year, $periods);

            if ($exceptions !== null) {
                $this->syncExceptions($year, $exceptions);
            }

            return $year;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $periods
     * @param  list<array<string, mixed>>|null  $exceptions
     */
    public function update(AcademicYear $year, array $attributes, array $periods, ?array $exceptions = null): AcademicYear
    {
        return DB::transaction(function () use ($year, $attributes, $periods, $exceptions): AcademicYear {
            $year->update($attributes);
            $this->syncPeriods($year, $periods);

            if ($exceptions !== null) {
                $this->syncExceptions($year, $exceptions);
            }

            return $year->refresh();
        });
    }

    /**
     * Diffs the submitted periods against what already exists, instead of
     * deleting and recreating every period on every save.
     *
     * A submitted period carrying an existing ulid is updated in place — its
     * database id, and anything that already hangs off it (snapshots,
     * instruments, classifications, ...) survive untouched. A submitted
     * period without a ulid is new. An existing period whose ulid stops being
     * submitted is a removal candidate: it is deleted only when nothing
     * depends on it yet (assertRemovable() below); otherwise the whole update
     * is rejected — and, since this runs inside the caller's DB::transaction,
     * nothing submitted alongside it is partially applied either.
     *
     * @param  list<array<string, mixed>>  $periods
     */
    protected function syncPeriods(AcademicYear $year, array $periods): void
    {
        $existing = $year->periods()->get()->keyBy('ulid');
        $submittedUlids = collect($periods)->pluck('ulid')->filter()->all();

        $toRemove = $existing->reject(
            fn (AcademicPeriod $period) => in_array($period->ulid, $submittedUlids, true),
        );

        // Guard every removal BEFORE anything is written — a rejected update
        // then never leaves a partial trail to roll back in the first place.
        foreach ($toRemove as $period) {
            $this->assertRemovable($period);
        }

        foreach ($toRemove as $period) {
            $period->delete();
        }

        $this->applySequences($year, $periods, $existing);

        // Every kept period is written to its real final state first...
        foreach ($periods as $periodData) {
            $existingPeriod = $this->existingFor($periodData, $existing);

            if ($existingPeriod !== null) {
                $existingPeriod->fill($this->periodAttributes($periodData));
                $existingPeriod->save();
            }
        }

        // ...and only then are brand-new periods created — by now every kept
        // period already holds its own final sequence, so a new period can
        // never collide with one that is still mid-move.
        foreach ($periods as $periodData) {
            if ($this->existingFor($periodData, $existing) === null) {
                // Periods start as draft; opening/closing them is a separate
                // action, not part of setting up the year's structure.
                $year->periods()->create([
                    ...$this->periodAttributes($periodData),
                    'status' => AcademicPeriodStatus::Draft->value,
                ]);
            }
        }
    }

    /**
     * O mesmo diff das exceções letivas (Fase 5.4) — e DELIBERADAMENTE MAIS
     * SIMPLES do que o dos períodos aqui em cima.
     *
     * A forma é a mesma e é o que interessa que seja: uma exceção submetida com
     * um ulid existente é atualizada NO SÍTIO (a linha sobrevive, e sobreviverá
     * a tudo o que um dia venha a apontar-lhe); uma submetida sem ulid é nova;
     * uma que existe e deixou de ser submetida é removida.
     *
     * O QUE NÃO SE PORTOU DAQUI DE CIMA, E PORQUÊ:
     *
     *   - NÃO HÁ `applySequences()`. Aquele bailado de estacionar sequências
     *     num valor livre antes de escrever as definitivas existe por uma razão
     *     exata: `academic_periods` tem UNIQUE(academic_year_id, sequence) e o
     *     MySQL não tem constraints diferidas, pelo que trocar dois períodos de
     *     ordem colidia a meio. `academic_calendar_exceptions` não tem ordem
     *     nenhuma — nem coluna, nem índice único — e duas exceções podem até
     *     cair no mesmo dia (um feriado que também é dia não letivo). Não há
     *     colisão possível para dançar à volta.
     *
     *   - NÃO HÁ `assertRemovable()`. Aquilo transforma nove chaves
     *     estrangeiras RESTRICT numa mensagem legível. Nada aponta ainda para
     *     esta tabela: nenhuma tabela tem `academic_calendar_exception_id`, e a
     *     materialização de aulas, que virá a lê-la, é uma fase à parte e lê-a
     *     por datas e não por FK. Uma lista de dependentes vazia mantida «para
     *     o caso» seria uma lista que ninguém se lembraria de atualizar.
     *
     * Fica portanto o diff em três passos — remover, atualizar, criar — e mais
     * nada. Corre dentro da DB::transaction de quem chama, tal como o dos
     * períodos: se os períodos rejeitarem a gravação, isto não fica meio feito.
     *
     * `source` NÃO ENTRA EM `exceptionAttributes()`, exatamente pela mesma razão
     * que `status` não entra em `periodAttributes()`: é uma coisa que se decide
     * uma vez, ao criar, e nunca um efeito secundário de gravar o formulário.
     * Uma exceção nasce «manual» — é a única proveniência que esta fase escreve
     * — e editá-la não a torna noutra coisa.
     *
     * @param  list<array<string, mixed>>  $exceptions
     */
    protected function syncExceptions(AcademicYear $year, array $exceptions): void
    {
        $existing = $year->exceptions()->get()->keyBy('ulid');
        $submittedUlids = collect($exceptions)->pluck('ulid')->filter()->all();

        $existing
            ->reject(fn (AcademicCalendarException $exception): bool => in_array($exception->ulid, $submittedUlids, true))
            ->each(fn (AcademicCalendarException $exception) => $exception->delete());

        foreach ($exceptions as $exceptionData) {
            $ulid = $exceptionData['ulid'] ?? null;
            $current = $ulid === null ? null : $existing->get($ulid);

            if ($current !== null) {
                $current->fill($this->exceptionAttributes($exceptionData));
                $current->save();

                continue;
            }

            $year->exceptions()->create([
                ...$this->exceptionAttributes($exceptionData),
                'source' => AcademicCalendarExceptionSource::Manual->value,
            ]);
        }
    }

    /**
     * The plain-column attributes shared by an updated existing exception and a
     * freshly-created one, so the two branches in syncExceptions() never drift.
     * `source` is deliberately absent — see the docblock above.
     *
     * @param  array<string, mixed>  $exceptionData
     * @return array<string, mixed>
     */
    private function exceptionAttributes(array $exceptionData): array
    {
        return [
            'type' => $exceptionData['type'],
            'title' => $exceptionData['title'],
            'starts_on' => $exceptionData['starts_on'],
            'ends_on' => $exceptionData['ends_on'],
            // Uma observação em branco é a ausência de observação, e escreve-se
            // null — não uma string vazia que o leitor depois teria de saber
            // tratar como se fosse null.
            'note' => ($exceptionData['note'] ?? null) === '' ? null : ($exceptionData['note'] ?? null),
        ];
    }

    /**
     * `sequence` carries UNIQUE(academic_year_id, sequence). MySQL has no
     * deferred unique constraints, so writing a kept period's new sequence
     * straight away can collide with whatever currently holds that value —
     * classically, swapping two periods (A:1,B:2 -> B:1,A:2) would write A's
     * new sequence 2 while B still holds it.
     *
     * Every kept period whose sequence is actually changing is first parked
     * on a value that collides with NEITHER what any period currently holds
     * NOR what any submitted period will finally hold, and only then given
     * its real final value in syncPeriods() above. A period whose sequence
     * is not changing is left alone entirely, so a genuinely unchanged save
     * touches it not at all (Eloquent's own dirty tracking then issues it
     * zero queries).
     *
     * `sequence` is an unsignedTinyInteger (0-255) — unlike the wider columns
     * InstrumentBuilder parks with a large constant offset, there is no room
     * here to assume any fixed value is free, so the actual free value is
     * searched for instead.
     *
     * @param  list<array<string, mixed>>  $periods
     * @param  Collection<string, AcademicPeriod>  $existing
     */
    private function applySequences(AcademicYear $year, array $periods, Collection $existing): void
    {
        // Every value any period will occupy once this save is done — a kept
        // period's sequence must never be temporarily parked on one of these,
        // or the later write of that period's own real value would collide
        // with the still-parked one.
        $finalSequences = collect($periods)->pluck('sequence')->map(fn ($s) => (int) $s)->all();

        // What is actually on the table right now, after removals above but
        // before any of this pass's writes.
        $liveSequences = $year->periods()->pluck('sequence')->all();

        foreach ($periods as $periodData) {
            $existingPeriod = $this->existingFor($periodData, $existing);

            if ($existingPeriod === null || (int) $periodData['sequence'] === $existingPeriod->sequence) {
                continue;
            }

            $reserved = array_unique(array_merge($liveSequences, $finalSequences));
            $temp = 255;
            while (in_array($temp, $reserved, true)) {
                $temp--;
            }

            $liveSequences = array_values(array_diff($liveSequences, [$existingPeriod->sequence]));
            $liveSequences[] = $temp;

            $existingPeriod->update(['sequence' => $temp]);
        }
    }

    /**
     * @param  array<string, mixed>  $periodData
     * @param  Collection<string, AcademicPeriod>  $existing
     */
    private function existingFor(array $periodData, Collection $existing): ?AcademicPeriod
    {
        $ulid = $periodData['ulid'] ?? null;

        return $ulid === null ? null : $existing->get($ulid);
    }

    /**
     * The plain-column attributes shared by an updated existing period and a
     * freshly-created one, so the two branches in syncPeriods() never drift
     * on which fields a period carries. `status` is deliberately absent: a
     * period always starts Draft, and opening/closing an existing one is a
     * separate action, never a side effect of saving the year's structure.
     *
     * @param  array<string, mixed>  $periodData
     * @return array<string, mixed>
     */
    private function periodAttributes(array $periodData): array
    {
        return [
            'label' => $periodData['label'],
            'kind' => $periodData['kind'],
            'sequence' => $periodData['sequence'],
            'starts_on' => $periodData['starts_on'],
            'ends_on' => $periodData['ends_on'],
        ];
    }

    /**
     * Refuses to remove a period anything still points to. The FK is
     * RESTRICT on every table in DEPENDENT_MODELS, so this is what turns that
     * into a clear message instead of a raw database error.
     */
    private function assertRemovable(AcademicPeriod $period): void
    {
        if ($this->hasDependents($period)) {
            throw AcademicYearValidationException::cannotRemovePeriodWithDependents($period->label);
        }
    }

    /**
     * Whether anything in the nine RESTRICT-constrained tables above still
     * points at this period. KEEP DEPENDENT_MODELS IN SYNC if a tenth
     * dependent table is ever added — the same risk this class's docblock
     * already flagged once, before any of these tables existed.
     */
    private function hasDependents(AcademicPeriod $period): bool
    {
        foreach (self::DEPENDENT_MODELS as $model) {
            $exists = $model::query()
                // A soft-deleted row (EvidenceRecord, Intervention) still
                // physically exists and still trips the RESTRICT constraint
                // on DELETE, so it must count here exactly as a live row
                // would. Removing a global scope a given model never
                // registered is a harmless no-op, so this is safe to call
                // unconditionally for every model in the list above.
                ->withoutGlobalScope(SoftDeletingScope::class)
                ->where('academic_period_id', $period->id)
                ->exists();

            if ($exists) {
                return true;
            }
        }

        return false;
    }
}
