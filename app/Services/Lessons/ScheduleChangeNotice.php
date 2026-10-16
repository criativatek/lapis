<?php

namespace App\Services\Lessons;

use App\Models\Lesson;
use Illuminate\Support\Collection;

/**
 * A EXPLICAÇÃO, EM PORTUGUÊS, DO QUE O HORÁRIO NÃO MUDOU (0.168.0).
 *
 * Alterar ou terminar um tempo nunca apaga nem muda de turma uma aula que
 * carrega conteúdo (sumário, plano, resultado, assiduidade — rascunho incluído):
 * essas aulas ficam na turma e às horas em que estavam. Isso é a garantia, mas
 * sem uma palavra o professor vê «Horário guardado» e uma aula ainda à hora
 * antiga, e conclui que a alteração falhou. Esta classe diz o que ficou, quais
 * e porquê.
 *
 * Só descreve; quem altera o horário calcula QUAIS são as aulas preservadas.
 * Sem nenhuma, devolve `null` e a mensagem habitual segue sozinha.
 */
final class ScheduleChangeNotice
{
    /** Datas escritas por extenso; as restantes ficam em «e mais N». */
    private const MAX_DATES = 3;

    /**
     * @param  Collection<int, Lesson>  $lessons  as aulas preservadas, com `schoolClass` carregada
     * @param  string  $lead  «Horário guardado.» / «Horário terminado.»
     * @param  bool  $restFollowNewSchedule  acrescenta «As restantes passam a seguir o horário novo.»
     */
    public static function describe(Collection $lessons, string $lead, bool $restFollowNewSchedule): ?string
    {
        if ($lessons->isEmpty()) {
            return null;
        }

        $groups = $lessons
            ->sortBy('starts_at')
            ->groupBy(fn (Lesson $lesson): string => $lesson->schoolClass->label.'|'.$lesson->starts_at->format('H:i'))
            ->map(function (Collection $group): string {
                /** @var Lesson $first */
                $first = $group->first();

                return sprintf(
                    'na %s às %s — %s —',
                    $first->schoolClass->label,
                    $first->starts_at->format('H:i'),
                    self::dates(array_values($group->map(fn (Lesson $lesson): string => $lesson->starts_at->format('d/m'))->all())),
                );
            })
            ->all();
        $groups = array_values($groups);

        $count = $lessons->count();

        $message = sprintf(
            '%s %s com registos (sumário, plano ou assiduidade) %s %s para não se perder o que lá está.',
            $lead,
            $count === 1 ? '1 aula' : $count.' aulas',
            $count === 1 ? 'ficou' : 'ficaram',
            self::join($groups),
        );

        return $restFollowNewSchedule
            ? $message.' As restantes passam a seguir o horário novo.'
            : $message;
    }

    /**
     * «12/10», «12/10 e 14/10», «12/10, 14/10 e 19/10», «12/10, 14/10, 19/10 e mais 2».
     *
     * @param  list<string>  $dates
     */
    private static function dates(array $dates): string
    {
        $shown = array_slice($dates, 0, self::MAX_DATES);
        $more = count($dates) - count($shown);

        if ($more > 0) {
            return implode(', ', $shown).' e mais '.$more;
        }

        return self::join($shown);
    }

    /**
     * @param  list<string>  $parts
     */
    private static function join(array $parts): string
    {
        if (count($parts) <= 1) {
            return $parts[0] ?? '';
        }

        $last = array_pop($parts);

        return implode(', ', $parts).' e '.$last;
    }
}
