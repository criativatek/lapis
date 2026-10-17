<?php

namespace App\Actions\Timetable;

use App\Models\TimetableActivity;
use App\Models\TimetableActivitySession;
use App\Models\TimetableActivitySessionStatus;
use App\Services\Timetable\ActivityOccurrences;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Guarda o registo de UMA sessão de uma «outra atividade» (0.170.0).
 *
 * É O ÚNICO SÍTIO ONDE UMA SESSÃO NASCE. Ver o horário ou a semana nunca chega
 * aqui; nada converte atividades existentes em sessões.
 *
 * IDEMPOTENTE POR (ATIVIDADE, DATA). A linha da atividade é bloqueada
 * (`lockForUpdate`) e a sessão da data lida sob esse bloqueio, com o UNIQUE da
 * tabela por baixo: um pedido repetido, um duplo clique ou duas abas encontram
 * a sessão que o primeiro criou e editam-na. O mesmo bloqueio serializa a
 * atribuição do número da sessão.
 *
 * PARA CRIAR, a data tem de ser uma ocorrência do bloco (dia da semana certo,
 * vigência a cobri-la) e não pode ser um dia não letivo. Uma sessão que já
 * existe edita-se sempre — incluindo uma que ficou fora do horário atual.
 *
 * NUMERAÇÃO (opcional, por atividade): nunca entra em `LessonNumbering`. Ao
 * passar a «realizada» sem número, recebe o maior número das sessões da MESMA
 * atividade, do mesmo professor e do mesmo ano letivo, mais um, nunca abaixo
 * do número inicial. Ao sair de «realizada» o número é limpo, sem renumerar as
 * outras.
 */
final class SaveActivitySession
{
    public function __construct(private readonly ActivityOccurrences $occurrences) {}

    /**
     * Guarda a sessão desta data, criando-a se ainda não existir.
     */
    public function forOccurrence(
        TimetableActivity $activity,
        string $date,
        ?string $summary,
        bool $summaryGiven,
        ActivitySessionAction $action,
    ): TimetableActivitySession {
        return DB::transaction(function () use ($activity, $date, $summary, $summaryGiven, $action): TimetableActivitySession {
            /** @var TimetableActivity $locked */
            $locked = TimetableActivity::query()->whereKey($activity->getKey())->lockForUpdate()->firstOrFail();

            $session = $locked->sessions()->whereDate('occurs_on', $date)->lockForUpdate()->first();

            if ($session === null) {
                $this->assertCanCreate($locked, $date, $summary, $action);
                $session = $this->create($locked, $date);
            }

            return $this->apply($session, $locked, $summary, $summaryGiven, $action);
        });
    }

    /**
     * Edita uma sessão que já existe (por ulid) — sempre possível, esteja ou
     * não no horário atual.
     */
    public function forSession(
        TimetableActivitySession $session,
        ?string $summary,
        bool $summaryGiven,
        ActivitySessionAction $action,
    ): TimetableActivitySession {
        return DB::transaction(function () use ($session, $summary, $summaryGiven, $action): TimetableActivitySession {
            $activity = $session->timetable_activity_id === null
                ? null
                : TimetableActivity::query()->whereKey($session->timetable_activity_id)->lockForUpdate()->first();

            /** @var TimetableActivitySession $locked */
            $locked = TimetableActivitySession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            return $this->apply($locked, $activity, $summary, $summaryGiven, $action);
        });
    }

    private function assertCanCreate(TimetableActivity $activity, string $date, ?string $summary, ActivitySessionAction $action): void
    {
        if (! $activity->occursOn($date)) {
            throw ValidationException::withMessages([
                'occurs_on' => __('Esta atividade não tem uma sessão nesta data.'),
            ]);
        }

        if (! $this->occurrences->isRegistrable($activity, $date)) {
            throw ValidationException::withMessages([
                'occurs_on' => __('Este dia é não letivo no calendário: não se pode registar uma sessão.'),
            ]);
        }

        // Sem texto, só um estado cria a sessão: «guardar» um registo vazio
        // não regista nada.
        if ($summary === null && in_array($action, [ActivitySessionAction::Save, ActivitySessionAction::Reopen], true)) {
            throw ValidationException::withMessages([
                'summary' => __('Escreve o sumário antes de guardar.'),
            ]);
        }
    }

    private function create(TimetableActivity $activity, string $date): TimetableActivitySession
    {
        $session = new TimetableActivitySession;
        $session->forceFill([
            'user_id' => $activity->user_id,
            'timetable_activity_id' => $activity->getKey(),
            'activity_kind' => $activity->kind->value,
            'activity_title' => $activity->title,
            'class_id' => $activity->class_id,
            'academic_year_id' => $this->occurrences->academicYearFor($activity, $date)?->getKey(),
            'occurs_on' => $date,
            'starts_at' => $activity->starts_at,
            'ends_at' => $activity->ends_at,
            'status' => TimetableActivitySessionStatus::Planned,
        ]);
        $session->save();

        return $session;
    }

    private function apply(
        TimetableActivitySession $session,
        ?TimetableActivity $activity,
        ?string $summary,
        bool $summaryGiven,
        ActivitySessionAction $action,
    ): TimetableActivitySession {
        if ($summaryGiven) {
            $session->summary = $summary;
        }

        $status = match ($action) {
            ActivitySessionAction::Save => $session->status,
            ActivitySessionAction::Done => TimetableActivitySessionStatus::Done,
            ActivitySessionAction::NotHeld => TimetableActivitySessionStatus::NotHeld,
            ActivitySessionAction::Reopen => TimetableActivitySessionStatus::Planned,
        };
        $session->status = $status;

        if ($status !== TimetableActivitySessionStatus::Done) {
            $session->session_number = null;
        } elseif ($session->session_number === null && $activity !== null && $activity->numbering_enabled) {
            $session->session_number = $this->nextNumber($session, $activity);
        }

        $session->save();

        return $session;
    }

    /**
     * O maior número já dado a uma sessão desta atividade, deste professor e
     * deste ano letivo, mais um — e nunca abaixo do número inicial.
     */
    private function nextNumber(TimetableActivitySession $session, TimetableActivity $activity): int
    {
        $highest = TimetableActivitySession::query()
            ->where('timetable_activity_id', $activity->getKey())
            ->where('user_id', $session->user_id)
            ->when(
                $session->academic_year_id === null,
                fn ($query) => $query->whereNull('academic_year_id'),
                fn ($query) => $query->where('academic_year_id', $session->academic_year_id),
            )
            ->whereKeyNot($session->getKey())
            ->max('session_number');

        return max((int) $activity->numbering_start, ((int) $highest) + 1);
    }
}
