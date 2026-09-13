<?php

namespace App\Services\Lessons;

use App\Models\Lesson;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * «Já existe uma aula desta turma neste horário.»
 *
 * O SÍTIO ÚNICO onde se decide se duas ocorrências da mesma turma podem
 * coexistir no mesmo pedaço de tempo. Tudo o que cria ou move uma aula passa
 * por aqui — criação manual, inserção intermédia, deslocamento, revisão do
 * horário recorrente e materialização —, precisamente para que a resposta não
 * possa divergir consoante o caminho.
 *
 * A REGRA É DE PARTICIPANTES, NÃO DE IGUALDADE DE HORAS. Duas aulas colidem
 * quando os seus intervalos se cruzam (14:10–15:00 contra 14:30–15:20 cruzam-se,
 * e nenhuma comparação de igualdade daria por isso) E quando os seus públicos
 * se intersetam:
 *
 *   turma inteira × turma inteira  → colide (é a mesma gente)
 *   turma inteira × grupo          → colide (o grupo está dentro da turma)
 *   grupo A × grupo A              → colide (é a mesma gente)
 *   grupo A × grupo B              → NÃO colide — é exatamente para isto que
 *                                    as turmas desdobradas existem: T1 e T2 têm
 *                                    aula à mesma hora, com professores ou
 *                                    espaços diferentes, e são duas ocorrências
 *                                    reais distintas (0.139.0)
 *
 * Turmas diferentes nunca colidem entre si aqui: o horário de duas turmas do
 * mesmo professor é um problema de agenda pessoal, não de integridade dos
 * dados de uma turma, e bloqueá-lo recusaria horários que hoje são válidos.
 *
 * ROOT CAUSE QUE ISTO FECHA. A chave `lessons_class_slot_start_unique` é
 * (turma, tempo do horário, início) — INCLUI o tempo do horário —, pelo que
 * dois tempos distintos da mesma turma à mesma hora produzem duas aulas que a
 * chave nunca vê. A chave continua onde está (é ela que torna a materialização
 * idempotente); o que faltava, e passa a existir aqui, é a regra semântica por
 * cima dela.
 */
final class LessonConflicts
{
    /**
     * A aula que colide com este tempo, ou NULL quando o caminho está livre.
     *
     * `$ignoreLessonId` existe para a edição e para o deslocamento: uma aula
     * nunca colide consigo própria quando é ela que está a ser movida.
     */
    public function conflictingLesson(
        int $classId,
        ?int $classGroupId,
        CarbonImmutable $startsAt,
        ?CarbonImmutable $endsAt,
        ?int $ignoreLessonId = null,
    ): ?Lesson {
        $query = Lesson::query()
            ->where('class_id', $classId)
            ->where($this->incompatibleAudienceScope($classGroupId))
            ->where($this->overlappingTimeScope($startsAt, $endsAt))
            ->with(['classGroup', 'schoolClass'])
            ->orderBy('starts_at');

        if ($ignoreLessonId !== null) {
            $query->whereKeyNot($ignoreLessonId);
        }

        return $query->first();
    }

    /**
     * A mesma pergunta, em português e já com data, hora e grupo lá dentro —
     * porque «já existe uma aula» sem dizer qual manda o professor procurá-la à
     * mão pela semana toda.
     */
    public function conflictMessage(Lesson $conflict): string
    {
        $timezone = 'Europe/Lisbon';
        $starts = CarbonImmutable::parse($conflict->starts_at)->setTimezone($timezone);
        $ends = $conflict->ends_at === null
            ? null
            : CarbonImmutable::parse($conflict->ends_at)->setTimezone($timezone);

        $when = $starts->format('d/m/Y').' às '.$starts->format('H:i')
            .($ends === null ? '' : '–'.$ends->format('H:i'));

        return __('Já existe uma aula desta turma neste horário: :context, a :when.', [
            'context' => $conflict->contextLabel(),
            'when' => $when,
        ]);
    }

    /**
     * Dois tempos do horário recorrente da mesma turma que se pisam.
     *
     * Comparados no mesmo dia da semana, com públicos incompatíveis pela mesma
     * regra de cima, e — decisivo — só quando as suas JANELAS DE VIGÊNCIA se
     * cruzam. Sem essa última condição, rever um tempo (que fecha a versão
     * antiga a 31/12 e abre a nova a 01/01) passaria a acusar a versão que
     * acabou de fechar, e o versionamento do horário deixava de funcionar.
     */
    public function conflictingSlot(
        SchoolClass $class,
        ?int $classGroupId,
        int $dayOfWeek,
        string $startsAt,
        string $endsAt,
        ?string $startsOn,
        ?string $endsOn,
        ?int $ignoreSlotId = null,
    ): ?RecurringLessonSlot {
        $query = RecurringLessonSlot::query()
            ->where('class_id', $class->getKey())
            ->where('day_of_week', $dayOfWeek)
            ->where($this->incompatibleAudienceScope($classGroupId))
            ->with('classGroup');

        if ($ignoreSlotId !== null) {
            $query->whereKeyNot($ignoreSlotId);
        }

        // Janelas abertas de um dos lados cruzam-se com tudo desse lado: um
        // `starts_on` NULL quer dizer «desde sempre», e um `ends_on` NULL
        // «até indicação em contrário».
        if ($endsOn !== null) {
            $query->where(fn (Builder $inner) => $inner
                ->whereNull('starts_on')
                ->orWhere('starts_on', '<=', $endsOn));
        }

        if ($startsOn !== null) {
            $query->where(fn (Builder $inner) => $inner
                ->whereNull('ends_on')
                ->orWhere('ends_on', '>=', $startsOn));
        }

        // A SOBREPOSIÇÃO DE HORAS É DECIDIDA EM PHP, e não em SQL. `starts_at` e
        // `ends_at` são colunas `time`: em MySQL comparam-se como horas, mas em
        // SQLite guardam-se como o texto que lá foi escrito — «14:10» ou
        // «14:10:00», consoante quem escreveu —, e aí `'15:00:00' > '15:00'` é
        // verdadeiro por ser mais comprido, não por ser mais tarde. Dois tempos
        // encostados passariam a acusar-se um ao outro, e só nos testes.
        //
        // O filtro em SQL já reduziu isto aos tempos da mesma turma, no mesmo dia
        // da semana e com público incompatível: são meia dúzia de linhas, e
        // compará-las em memória sai mais barato do que a fragilidade.
        $candidates = $query->get()->filter(
            fn (RecurringLessonSlot $slot): bool => $this->minutesOf($slot->starts_at) < $this->minutesOf($endsAt)
                && $this->minutesOf($slot->ends_at) > $this->minutesOf($startsAt),
        );

        return $candidates->first();
    }

    /**
     * «14:10», «14:10:00» e «14:10:59» são todos as 14:10 para efeitos de um
     * tempo do horário — a grelha de uma escola não tem segundos.
     */
    private function minutesOf(string $time): int
    {
        [$hours, $minutes] = array_pad(explode(':', trim($time)), 2, '0');

        return ((int) $hours) * 60 + (int) $minutes;
    }

    public function slotConflictMessage(RecurringLessonSlot $slot): string
    {
        $days = [1 => 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira',
            'sexta-feira', 'sábado', 'domingo'];

        return __('Já existe um tempo desta turma que se sobrepõe a este: :day, :from–:to:group.', [
            'day' => $days[$slot->day_of_week] ?? (string) $slot->day_of_week,
            'from' => substr($slot->starts_at, 0, 5),
            'to' => substr($slot->ends_at, 0, 5),
            'group' => $slot->classGroup === null ? ' (turma inteira)' : ' ('.$slot->classGroup->label.')',
        ]);
    }

    /**
     * «Quem está nesta aula interseta-se com quem está naquela?»
     *
     * Escrito uma vez e reaproveitado pelas aulas e pelos tempos do horário
     * porque é literalmente a mesma pergunta sobre a mesma coluna — duplicá-la
     * seria abrir a porta a que uma das duas passasse a responder outra coisa.
     */
    private function incompatibleAudienceScope(?int $classGroupId): Closure
    {
        return function (Builder $query) use ($classGroupId): void {
            // A turma inteira interseta-se com toda a gente da turma.
            if ($classGroupId === null) {
                return;
            }

            // Um grupo interseta-se com a turma inteira e consigo próprio,
            // e com mais nenhum grupo.
            $query->whereNull('class_group_id')
                ->orWhere('class_group_id', $classGroupId);
        };
    }

    /**
     * Intervalos que se cruzam: `a.inicio < b.fim && b.inicio < a.fim`.
     *
     * Aberto nos extremos de propósito — uma aula que acaba às 15:00 e outra
     * que começa às 15:00 encostam-se, não se sobrepõem, e são o caso normal de
     * dois tempos seguidos.
     *
     * `ends_at` é nullable na tabela: uma aula sem fim declarado é tratada como
     * um instante, e só colide com quem estiver mesmo a decorrer nela.
     */
    private function overlappingTimeScope(CarbonImmutable $startsAt, ?CarbonImmutable $endsAt): Closure
    {
        $effectiveEnd = $endsAt ?? $startsAt->addMinute();

        return function (Builder $query) use ($startsAt, $effectiveEnd): void {
            $query
                ->where('starts_at', '<', $effectiveEnd)
                ->where(fn (Builder $inner) => $inner
                    ->whereNull('ends_at')
                    ->orWhere('ends_at', '>', $startsAt));
        };
    }
}
