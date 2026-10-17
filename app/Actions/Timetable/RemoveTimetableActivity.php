<?php

namespace App\Actions\Timetable;

use App\Models\TimetableActivity;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Eliminar o bloco de uma «outra atividade» (0.170.0) sem perder o que o
 * professor escreveu.
 *
 *  - SEM sessões com registos: o bloco e as suas sessões vazias são apagados.
 *  - COM sessões com registos: o bloco NÃO é apagado. Fecha-se a vigência
 *    (`ends_on` = véspera da data de aplicação; por omissão, hoje) e apagam-se
 *    só as sessões vazias dessa data em diante. O histórico, o bloco e as
 *    sessões com registos ficam — o bloco continua a identificá-las.
 *
 * Apagar a atividade deixaria as sessões vazias órfãs (a FK é `nullOnDelete`),
 * por isso saem antes.
 */
final class RemoveTimetableActivity
{
    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    /**
     * @return array{outcome: 'deleted'|'closed', records: int, effective_from: string|null}
     */
    public function execute(TimetableActivity $activity, ?string $effectiveFrom): array
    {
        return DB::transaction(function () use ($activity, $effectiveFrom): array {
            /** @var TimetableActivity $locked */
            $locked = TimetableActivity::query()->whereKey($activity->getKey())->lockForUpdate()->firstOrFail();

            $records = $locked->sessions()->withRecords()->count();

            if ($records === 0) {
                $locked->sessions()->delete();
                $locked->delete();

                return ['outcome' => 'deleted', 'records' => 0, 'effective_from' => null];
            }

            $timezone = $this->currentOrganization->get()->timezone;
            $from = $effectiveFrom ?? CarbonImmutable::now($timezone)->toDateString();
            $endsOn = CarbonImmutable::parse($from)->subDay()->toDateString();

            // Fechar nunca ESTENDE: um bloco que já terminava antes fica como estava.
            if ($locked->ends_on !== null && $locked->ends_on->toDateString() < $endsOn) {
                $endsOn = $locked->ends_on->toDateString();
            }

            // Um bloco que ainda nem começou não pode fechar antes de começar.
            if ($locked->starts_on !== null && $locked->starts_on->toDateString() > $endsOn) {
                $endsOn = $locked->starts_on->toDateString();
            }

            $locked->forceFill(['ends_on' => $endsOn])->save();
            $locked->sessions()->withoutRecords()->whereDate('occurs_on', '>=', $from)->delete();

            return ['outcome' => 'closed', 'records' => $records, 'effective_from' => $from];
        });
    }
}
