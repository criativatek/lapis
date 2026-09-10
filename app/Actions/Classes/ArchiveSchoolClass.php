<?php

namespace App\Actions\Classes;

use App\Models\SchoolClass;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * ARQUIVAR, NUNCA APAGAR — o mesmo gesto que ArchiveClassGroup já dá a um
 * grupo, aqui aplicado à turma inteira.
 *
 * SEM PRÉ-CONDIÇÃO. Ao contrário de ArchiveClassGroup (que recusa se houver
 * tempos do horário ainda em vigor), arquivar uma turma não tem nada a
 * verificar: não pára nenhum processo em curso, só deixa de a mostrar nas
 * listas por omissão. Quem decide que já não há aulas nem avaliações a
 * lançar é o professor, não esta ação.
 *
 * O RESTAURAR é o mesmo botão ao contrário e também não verifica nada —
 * voltar a mostrar a turma não estraga nada do que já foi escrito.
 */
class ArchiveSchoolClass
{
    public function execute(SchoolClass $class): void
    {
        DB::transaction(function () use ($class): void {
            /** @var SchoolClass $locked */
            $locked = SchoolClass::query()->whereKey($class->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isArchived()) {
                return;
            }

            $locked->update(['archived_at' => CarbonImmutable::now()]);
        });
    }

    public function restore(SchoolClass $class): void
    {
        $class->update(['archived_at' => null]);
    }
}
