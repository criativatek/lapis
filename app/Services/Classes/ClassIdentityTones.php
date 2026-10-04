<?php

namespace App\Services\Classes;

use App\Models\ClassIdentityTone;
use App\Models\SchoolClass;
use Illuminate\Support\Facades\DB;

/**
 * Escolhe o tom (cor de identidade) de uma turma NOVA de um professor.
 *
 * REGRA: entre as turmas ATIVAS (não arquivadas) que o professor já leciona
 * NO MESMO ANO LETIVO, o tom MENOS usado; em empate, o primeiro da paleta.
 * Cada professor tem a sua própria cor para a mesma turma — dois
 * colegas de turma escolhem de forma independente.
 *
 * SÓ SE ESCOLHE O TOM DA TURMA QUE ENTRA, E NUNCA SE RECALCULA DEPOIS: o
 * professor associa «8.º F» ao azul, e uma cor que mudasse porque outra turma
 * foi arquivada ou filtrada deixava de ser identidade. Arquivar uma turma só
 * liberta o seu tom para a PRÓXIMA turma — não mexe em nenhuma existente.
 *
 * Lê com `DB::table` e não com os modelos, porque corre dentro do evento
 * `creating` do pivot, onde o tenant pode nem estar resolvido (seeders), e a
 * pergunta é sobre linhas de um só professor, sem organização à vista.
 */
final class ClassIdentityTones
{
    /**
     * Os tons que ESTE professor deu às turmas indicadas, numa só consulta
     * (nunca um por linha). Uma turma sem tom gravado fica de fora.
     *
     * @param  array<int, int|string>  $classIds
     * @return array<int, string> class_id => valor de ClassIdentityTone
     */
    public function forTeacher(int $userId, array $classIds): array
    {
        if ($classIds === []) {
            return [];
        }

        /** @var array<int, string> $tones */
        $tones = DB::table('class_teachers')
            ->where('user_id', $userId)
            ->whereIn('class_id', $classIds)
            ->whereNotNull('identity_tone')
            ->pluck('identity_tone', 'class_id')
            ->all();

        return $tones;
    }

    public function nextFor(int $userId, SchoolClass $class): ClassIdentityTone
    {
        /** @var array<string, int> $usage */
        $usage = DB::table('class_teachers')
            ->join('classes', 'classes.id', '=', 'class_teachers.class_id')
            ->where('class_teachers.user_id', $userId)
            ->where('classes.academic_year_id', $class->academic_year_id)
            ->where('class_teachers.class_id', '!=', $class->getKey())
            ->whereNull('classes.archived_at')
            ->whereNotNull('class_teachers.identity_tone')
            ->selectRaw('class_teachers.identity_tone as tone, count(*) as total')
            ->groupBy('class_teachers.identity_tone')
            ->pluck('total', 'tone')
            ->map(fn ($total): int => (int) $total)
            ->all();

        $chosen = null;
        $chosenUsage = PHP_INT_MAX;

        foreach (ClassIdentityTone::cases() as $tone) {
            $used = $usage[$tone->value] ?? 0;

            // `<` estrito: em empate fica o primeiro da paleta.
            if ($used < $chosenUsage) {
                $chosen = $tone;
                $chosenUsage = $used;
            }
        }

        return $chosen ?? ClassIdentityTone::Blue;
    }
}
