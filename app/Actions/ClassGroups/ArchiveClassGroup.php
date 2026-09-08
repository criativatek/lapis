<?php

namespace App\Actions\ClassGroups;

use App\Models\ClassGroup;
use App\Models\RecurringLessonSlot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ARQUIVAR, NUNCA APAGAR, assim que o grupo tem história.
 *
 * Um grupo que já governou pertenças ou aulas é preciso para as ler: a aula de
 * 12 de novembro diz «8.º F · T1» porque aponta para esta linha, e apagá-la
 * transformaria o sumário dessa aula numa aula da turma inteira, que é
 * falso. Arquivar deixa tudo isso legível e só fecha a porta ao que vem a
 * seguir — pertenças novas (ClassGroupMembershipRules::assertAcceptsMembers())
 * e tempos do horário novos.
 *
 * NÃO ARQUIVA EM SILÊNCIO POR CIMA DO HORÁRIO. Se houver tempos ainda em vigor
 * atribuídos a este grupo, arquivar produziria aulas futuras de um grupo que a
 * interface já não deixa escolher nem gerir — e o professor não saberia
 * porquê. A ação recusa e diz quantos são, para que ele reveja o horário
 * primeiro (§24 do briefing). É a solução segura: nada acontece até que o que
 * está por resolver esteja resolvido.
 *
 * O DESARQUIVAR é o mesmo botão ao contrário e não tem nada a verificar —
 * voltar a aceitar alunos não estraga nada do que já foi escrito.
 */
class ArchiveClassGroup
{
    private const TIMEZONE = 'Europe/Lisbon';

    public function execute(ClassGroup $classGroup): void
    {
        DB::transaction(function () use ($classGroup): void {
            /** @var ClassGroup $locked */
            $locked = ClassGroup::query()->whereKey($classGroup->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isArchived()) {
                return;
            }

            $openSlots = $this->openSlotsCount($locked);

            if ($openSlots > 0) {
                throw ValidationException::withMessages([
                    'class_group_id' => trans_choice(
                        '{1}Há :count tempo no horário atribuído a :label. Reveja o horário antes de arquivar o grupo.'
                        .'|[2,*]Há :count tempos no horário atribuídos a :label. Reveja o horário antes de arquivar o grupo.',
                        $openSlots,
                        ['count' => $openSlots, 'label' => $locked->label],
                    ),
                ]);
            }

            $locked->update(['archived_at' => CarbonImmutable::now()]);
        });
    }

    public function restore(ClassGroup $classGroup): void
    {
        $classGroup->update(['archived_at' => null]);
    }

    /**
     * Quantos tempos do horário deste grupo ainda produzem aulas — os que não
     * têm fim, e os que só acabam de hoje em diante.
     *
     * O MESMO FILTRO QUE O ECRÃ DA TURMA JÁ USA para decidir que tempos mostra
     * (ClassController::show()), e de propósito: a lista onde o professor vai
     * resolver o problema tem de ser exatamente a lista que o bloqueou.
     */
    protected function openSlotsCount(ClassGroup $classGroup): int
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->toDateString();

        return RecurringLessonSlot::query()
            ->where('class_group_id', $classGroup->getKey())
            ->where(fn (Builder $query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
            ->count();
    }
}
