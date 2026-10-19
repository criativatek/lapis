<?php

namespace App\Services\Lessons;

use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Models\RecurringLessonSlot;
use App\Support\Lessons\LessonNumberingFreeze;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * «Lição 1, Lição 2, Lição 3» — a numeração sequencial das aulas, garantida no
 * servidor e em mais lado nenhum.
 *
 * UMA SÓ FUNÇÃO, CHAMADA DEPOIS DE TUDO. Em vez de cada caminho de escrita
 * calcular o seu próximo número — a materialização, a inserção intermédia, a
 * eliminação, o deslocamento —, todos eles mexem no que têm de mexer e depois
 * pedem aqui uma renumeração da turma. A ordem numérica é recalculada da ordem
 * cronológica real, e não mantida em paralelo com ela.
 *
 * O ÂMBITO É A TURMA (0.145.2). T1 e T2 não são turmas: são desdobramentos da
 * mesma turma. Uma turma de apoio é uma SchoolClass própria e tem, por isso,
 * sequência própria.
 *
 * A UNIDADE DE LIÇÃO É EXPLÍCITA, NUNCA INFERIDA DA DATA:
 *  - uma aula da turma inteira é, sozinha, uma unidade;
 *  - uma aula de grupo cujo tempo do horário não tem `split_lesson_key` é,
 *    sozinha, uma unidade;
 *  - aulas de grupo com o mesmo `lesson_unit_key` são a mesma lição e partilham
 *    o número.
 *
 * A LIGAÇÃO É GRAVADA UMA VEZ E NUNCA RECALCULADA. Uma aula de grupo ainda sem
 * `lesson_unit_key`, vinda de um tempo com `split_lesson_key` K, junta-se à
 * lição mais antiga de K a que falte o seu grupo (e que não seja anterior ao
 * início desse tempo); se não houver, abre uma lição nova. É uma fila por grupo
 * dentro dos tempos ligados: se T2 perde uma aula por feriado, a T2 seguinte
 * continua a ser a lição que ficou por dar, e não a da semana em que acontece.
 * Uma aula já ligada nunca muda de lição — nem ao reabrir a semana, nem ao
 * reexecutar a renumeração.
 *
 * A ORDEM DAS UNIDADES é a da sua primeira aula (`starts_at`, depois `id`).
 *
 * UMA AUSÊNCIA DO PROFESSOR NÃO NUMERA (0.146.0): não entra na sequência. Uma
 * atividade da turma noutra atividade letiva numera como uma lecionada, e é
 * tão intocável como ela.
 *
 * O HISTÓRICO LECIONADO É INTOCÁVEL (§14) no funcionamento normal: se uma
 * operação exigisse mudar o número de uma aula lecionada, é recusada antes de
 * escrever. A única exceção é `rebuild()`, a correção histórica controlada que
 * só `lapis:renumber-lessons --apply` chama.
 *
 * O NÚMERO É SUGERIDO, NÃO IMPOSTO (0.169.0). O professor pode indicar o número
 * (ou o intervalo «11–12») de uma aula — quem começa a usar o Lapispro a meio do
 * ano, ou precisa de acertar a sequência. Uma unidade de lição é então de um de
 * três TIPOS:
 *  - MANUAL: o professor indicou o número (`lesson_number_manual`). Nunca é
 *    recalculado;
 *  - HISTÓRICO: não é manual, mas já foi lecionada (ou fechada com número). O
 *    número que tem é a história e também não é recalculado;
 *  - LIVRE: todas as outras — o número é SUGERIDO por esta classe.
 * MANUAL e HISTÓRICO são FIXAS: uma unidade fixa nunca muda de número por
 * derivação. As livres derivam das fixas: depois da unidade numerada anterior
 * +1, saltando os números já ocupados, e — se vierem antes da primeira fixa —
 * para trás a partir dela. Quando não há lugar exato, a livre fica SEM número
 * (nunca duplica, nunca invade um intervalo fixo), exceto se já tinha um número
 * guardado e livre, que é preservado. Numa turma sem números manuais e coerente,
 * o resultado é idêntico ao da numeração sempre derivada de antes.
 *
 * UMA AULA, UM NÚMERO (0.172.0, ADR-0017). Uma ocorrência corresponde a uma só
 * lição. O fim de um intervalo antigo («Lições 12–13», `lesson_number_end`,
 * gravado até à 0.171.0) é LEGADO: conserva-se e mostra-se tal como está, mas
 * NÃO OCUPA números nem empurra a sequência — a lição seguinte à «12–13» é a 13.
 * Nenhum caminho desta classe cria um fim novo; um fim gravado só se mantém
 * enquanto a sua aula mantiver o número.
 */
final class LessonNumbering
{
    private const MANUAL = 'manual';

    private const HISTORY = 'history';

    private const FREE = 'free';

    /** Defesa contra dados estragados ao LER um fim legado; nenhum fim novo é escrito. */
    private const MAX_SPAN = 999;

    /**
     * Liga e renumera a turma pela ordem cronológica real.
     *
     * SEMPRE DENTRO DE UMA TRANSAÇÃO DE QUEM CHAMA.
     *
     * A GUARDA DO HISTÓRICO (§14). Sem `$guardSnapshot`, é a de sempre: recusa se
     * alguma aula fechada, com número e não manual, ficasse com um número
     * posicional diferente do que tem. Com `$guardSnapshot` — o que
     * `guardSnapshot()` devolveu ANTES das escritas da operação —, recusa só se
     * alguma das aulas que estavam COERENTES nesse momento deixasse de estar. É o
     * que impede uma incoerência criada pelo professor (um número manual que não
     * respeita a ordem) de deixar a turma presa para sempre: a operação seguinte
     * só é recusada se ela própria estragar uma aula que estava bem.
     *
     * @param  array<int, int>|null  $guardSnapshot  lesson_id => número, de `guardSnapshot()`
     * @return array<int, int|null> lesson_id => número novo (NULL = ficou sem número), só das aulas que mudaram
     *
     * @throws ValidationException quando fechar a sequência exigiria mudar o
     *                             número de uma aula já lecionada.
     */
    public function resequence(int $classId, ?array $guardSnapshot = null): array
    {
        $lessons = $this->sequence($classId);
        $links = $this->link($lessons, $this->slotKeys($classId));

        // A recusa acontece antes de qualquer escrita, ligações incluídas.
        $this->guardTaughtHistory($lessons, $guardSnapshot);

        $changes = $this->writable($this->changesFor($this->project($lessons)));
        $this->writeLinks($links);
        $this->writeNumbers($changes);

        return array_map(fn (array $change): ?int => $change['to'], $changes);
    }

    /**
     * As aulas fechadas e COERENTES neste momento: lesson_id => número, para as
     * fechadas com número, não manuais, cujo número guardado é igual ao
     * posicional. É a fotografia que `resequence()` compara depois das escritas
     * de uma operação estrita (eliminar, inserir, fechar, reconciliar) — tirada
     * por quem chama, já dentro do bloqueio da turma e ANTES de escrever.
     *
     * @return array<int, int>
     */
    public function guardSnapshot(int $classId): array
    {
        $lessons = $this->sequence($classId);
        $this->link($lessons, $this->slotKeys($classId));
        $positions = $this->positions($lessons);
        $snapshot = [];

        foreach ($lessons as $lesson) {
            $position = $positions[(int) $lesson->getKey()] ?? null;

            if ($position !== null && $lesson->isClosedAndNumbered() && $lesson->lesson_number === $position) {
                $snapshot[(int) $lesson->getKey()] = $position;
            }
        }

        return $snapshot;
    }

    /**
     * A mesma recusa de `resequence()`, calculada sobre um estado HIPOTÉTICO da
     * turma e sem escrever nada. É o que a pré-visualização de «Inserir aula»
     * usa: as aulas deslocadas chegam como cópias em memória com a data nova, e
     * a aula a inserir como um modelo nunca gravado.
     *
     * A POPULAÇÃO É A DE `sequence()`, decidida aqui e não por quem chama: uma
     * ausência do professor não é lição, e é posta de fora mesmo que chegue na
     * lista. Antes da 0.157.0 contava como uma — a pré-visualização da inserção
     * passa TODAS as aulas da turma —, e cada lecionada depois de uma ausência
     * «ganhava» mais um número: uma inserção em outubro era recusada por mudar
     * a Lição 3 de setembro para Lição 4, que a execução nunca faria.
     *
     * @param  iterable<Lesson>  $lessons  todas as aulas da turma, no estado hipotético
     * @return array<int, int|null> lesson_id => número novo, só das aulas gravadas que mudariam
     *
     * @throws ValidationException
     */
    public function previewHypotheticalSequence(int $classId, iterable $lessons): array
    {
        return array_map(
            fn (array $change): ?int => $change['to'],
            $this->previewHypotheticalChanges($classId, $lessons),
        );
    }

    /**
     * Como `previewHypotheticalSequence()`, mas com o intervalo completo
     * (início e fim) de cada aula gravada que mudaria.
     *
     * @param  iterable<Lesson>  $lessons
     * @return array<int, array{lesson: Lesson, from: int|null, from_end: int|null, from_manual: bool, to: int|null, to_end: int|null, manual: bool}>
     *
     * @throws ValidationException
     */
    public function previewHypotheticalChanges(int $classId, iterable $lessons): array
    {
        $sorted = new Collection(collect($lessons)
            ->reject(fn (Lesson $lesson): bool => $lesson->outcome === LessonOutcome::TeacherAbsent)
            ->sort(function (Lesson $left, Lesson $right): int {
                $byTime = $left->starts_at->getTimestamp() <=> $right->starts_at->getTimestamp();

                // Uma aula ainda sem id é a mais recente a nascer.
                return $byTime !== 0 ? $byTime : ($left->getKey() ?? PHP_INT_MAX) <=> ($right->getKey() ?? PHP_INT_MAX);
            })
            ->values()
            ->all());

        $this->link($sorted, $this->slotKeys($classId));

        // As coerentes AGORA (estado gravado) têm de continuar coerentes no
        // estado hipotético.
        $this->guardTaughtHistory($sorted, $this->guardSnapshot($classId));

        return $this->changesFor($this->project($sorted));
    }

    /**
     * A correção histórica: liga e renumera TUDO, lecionadas incluídas. Nunca é
     * chamada por um caminho normal da aplicação.
     *
     * AS UNIDADES MANUAIS CONTINUAM FIXAS: o comando corrige o que a derivação
     * errou, nunca o que o professor indicou. O histórico, esse sim, deixa de ser
     * fixo — é a razão de ser do comando.
     *
     * `$slotKeys` permite simular chaves de tempos ainda não gravadas (a
     * simulação do comando). Idempotente: as ligações gravadas não mudam e os
     * números são função delas e da cronologia.
     *
     * @param  array<int, string>  $slotKeyOverrides  slot_id => split_lesson_key
     * @return array{numbers: array<int, array{lesson: Lesson, from: int|null, from_end: int|null, from_manual: bool, to: int|null, to_end: int|null, manual: bool}>, links: array<int, string>}
     */
    public function rebuild(int $classId, bool $write = true, array $slotKeyOverrides = []): array
    {
        $lessons = $this->sequence($classId);
        $slotKeys = $this->slotKeys($classId);

        foreach ($slotKeyOverrides as $slotId => $key) {
            if (isset($slotKeys[$slotId])) {
                $slotKeys[$slotId]['key'] = $key;
            }
        }

        $links = $this->link($lessons, $slotKeys);
        $numbers = $this->writable($this->changesFor($this->project($lessons, historyFixed: false)));

        if ($write) {
            $this->writeLinks($links);
            $this->writeNumbers($numbers);
        }

        return ['numbers' => $numbers, 'links' => $links];
    }

    /**
     * A numeração no caminho automático: a materialização, que corre sozinha
     * sempre que alguém abre a semana, e por isso NUNCA FALHA.
     *
     * Liga as aulas novas e aplica a derivação, sem guarda: o histórico e os
     * números manuais são fixos e nenhum muda. Uma aula nova a que a derivação
     * não encontra lugar (a materializar uma semana anterior a uma Lição 1
     * lecionada, por exemplo) fica SEM número — já não recebe «o maior número +
     * 1», que a punha fora de ordem face ao histórico. O desvio fica registado.
     *
     * @param  list<int>  $newLessonIds  as aulas que esta materialização acabou de criar
     * @return array<int, int|null>
     */
    public function numberMaterializedLessons(int $classId, array $newLessonIds = []): array
    {
        $lessons = $this->sequence($classId);
        $links = $this->link($lessons, $this->slotKeys($classId));
        $changes = $this->writable($this->changesFor($this->project($lessons)));

        $this->writeLinks($links);
        $this->writeNumbers($changes);

        // NÃO SILENCIOSO: aulas novas (ou que tinham número) que ficaram sem
        // lugar na sequência. Só ids, nunca dados de alunos.
        $unnumbered = [];

        foreach ($changes as $lessonId => $change) {
            if ($change['to'] === null) {
                $unnumbered[$lessonId] = $lessonId;
            }
        }

        foreach ($newLessonIds as $lessonId) {
            $change = $changes[$lessonId] ?? null;
            $lesson = $lessons->first(fn (Lesson $candidate): bool => (int) $candidate->getKey() === $lessonId);

            if ($lesson !== null && ($change === null ? $lesson->lesson_number === null : $change['to'] === null)) {
                $unnumbered[$lessonId] = $lessonId;
            }
        }

        if ($unnumbered !== []) {
            Log::warning('lessons.numbering.unnumbered_without_room', [
                'class_id' => $classId,
                'lessons' => array_values($unnumbered),
            ]);
        }

        return array_map(fn (array $change): ?int => $change['to'], $changes);
    }

    /**
     * O que a renumeração FARIA, sem escrever nada (§20).
     *
     * @return array<int, array{lesson: Lesson, from: int|null, from_end: int|null, from_manual: bool, to: int|null, to_end: int|null, manual: bool}>
     */
    public function previewResequence(int $classId): array
    {
        $lessons = $this->sequence($classId);
        $this->link($lessons, $this->slotKeys($classId));

        return $this->writable($this->changesFor($this->project($lessons)));
    }

    /**
     * A turma como ficaria se a unidade de uma aula passasse a ter um número
     * imposto — sem escrever nada. É o cálculo único da alteração manual do
     * professor (SetLessonNumber): a pré-visualização e a execução leem o mesmo
     * resultado.
     *
     * `$override` impõe a uma unidade: `manual` = um número indicado (uma só
     * lição: um fim legado que a unidade tivesse é retirado); `automatic` = a
     * unidade deixa de ser manual e conta como LIVRE, mesmo que esteja fechada
     * (o número guardado é ignorado, e por isso também não é preservado).
     * `changes` compara sempre com o que está GRAVADO.
     *
     * @param  array{mode: string, start: int|null}|null  $override
     * @return array{records: list<array{lessons: list<Lesson>, kind: string, start: int|null, end: int|null}>, changes: array<int, array{lesson: Lesson, from: int|null, from_end: int|null, from_manual: bool, to: int|null, to_end: int|null, manual: bool}>, links: array<int, string>, target: int|null}
     */
    public function projectClass(int $classId, ?int $targetLessonId = null, ?array $override = null): array
    {
        $lessons = $this->sequence($classId);
        $links = $this->link($lessons, $this->slotKeys($classId));
        $originals = $this->triples($lessons);
        $target = null;

        if ($targetLessonId !== null) {
            foreach ($this->units($lessons) as $index => $unit) {
                foreach ($unit as $lesson) {
                    if ((int) $lesson->getKey() !== $targetLessonId) {
                        continue;
                    }

                    $target = $index;

                    foreach ($unit as $member) {
                        if ($override !== null && $override['mode'] === 'manual') {
                            $member->lesson_number = $override['start'];
                            $member->lesson_number_end = null;
                            $member->lesson_number_manual = true;
                        } elseif ($override !== null) {
                            $member->lesson_number = null;
                            $member->lesson_number_end = null;
                            $member->lesson_number_manual = false;
                        }
                    }
                }
            }
        }

        $records = $this->project($lessons);

        return [
            'records' => $records,
            'changes' => $this->changesFor($records, $originals),
            'links' => $links,
            'target' => $target,
        ];
    }

    /**
     * Uma ausência do professor numa aula MANUAL não gera saltos: a lição que
     * estava nela passa à próxima unidade da turma (qualquer público), desde que
     * essa seja LIVRE. Se for fixa — manual ou histórica —, o manual da ausente
     * perde-se. Chamado depois de a ausência estar gravada (já fora da sequência).
     *
     * Passa SÓ o número (0.172.0): a unidade que o recebe é uma aula nova para
     * este número e corresponde a uma só lição, mesmo que a ausente tivesse um
     * intervalo legado.
     *
     * @return bool se o número passou a outra unidade
     */
    public function handOverManualNumber(Lesson $absent, int $start): bool
    {
        // Congelada, nenhum número que já exista muda: o manual da ausente não
        // passa a outra aula (a ausente fica sem número, como sempre).
        if (LessonNumberingFreeze::frozen()) {
            return false;
        }

        $lessons = $this->sequence((int) $absent->class_id);
        $this->link($lessons, $this->slotKeys((int) $absent->class_id));

        foreach ($this->units($lessons) as $unit) {
            $first = $unit[0];
            $byTime = $first->starts_at->getTimestamp() <=> $absent->starts_at->getTimestamp();

            if ($byTime < 0 || ($byTime === 0 && $first->getKey() <= $absent->getKey())) {
                continue;
            }

            if ($this->fixedInterval($unit, true) !== null) {
                return false;
            }

            foreach ($unit as $lesson) {
                DB::table('lessons')->where('id', $lesson->getKey())->update([
                    'lesson_number' => $start,
                    'lesson_number_end' => null,
                    'lesson_number_manual' => true,
                ]);
            }

            return true;
        }

        return false;
    }

    /**
     * Escreve o que `projectClass()` calculou: as ligações novas e os números.
     * SEMPRE DENTRO DE UMA TRANSAÇÃO DE QUEM CHAMA, com a turma bloqueada.
     *
     * @param  array{changes: array<int, array{lesson: Lesson, from: int|null, from_end: int|null, from_manual: bool, to: int|null, to_end: int|null, manual: bool}>, links: array<int, string>}  $projection
     */
    public function commit(array $projection): void
    {
        $this->writeLinks($projection['links']);
        $this->writeNumbers($projection['changes']);
    }

    /**
     * Liga, EM MEMÓRIA, as aulas de grupo ainda sem lição. Devolve as ligações
     * novas das aulas gravadas, para quem as quiser escrever.
     *
     * @param  Collection<int, Lesson>  $lessons  ordenadas por starts_at, id
     * @param  array<int, array{key: string|null, from: int|null}>  $slotKeys
     * @return array<int, string> lesson_id => lesson_unit_key
     */
    private function link(Collection $lessons, array $slotKeys): array
    {
        // Por vínculo, as lições pela ordem em que apareceram; por lição, os
        // grupos que já têm aula nela e o instante da sua primeira aula.
        /** @var array<string, list<string>> $unitsBySplitKey */
        $unitsBySplitKey = [];
        /** @var array<string, array<int, true>> $groupsByUnit */
        $groupsByUnit = [];
        /** @var array<string, int> $firstByUnit */
        $firstByUnit = [];
        $links = [];

        foreach ($lessons as $lesson) {
            if ($lesson->class_group_id === null) {
                continue;
            }

            $slot = $lesson->recurring_lesson_slot_id === null ? null : ($slotKeys[$lesson->recurring_lesson_slot_id] ?? null);
            $splitKey = $slot['key'] ?? null;
            $groupId = $lesson->class_group_id;

            if ($lesson->lesson_unit_key !== null) {
                $unitKey = $lesson->lesson_unit_key;

                if (isset($groupsByUnit[$unitKey])) {
                    $groupsByUnit[$unitKey][$groupId] = true;
                } elseif ($splitKey !== null) {
                    $unitsBySplitKey[$splitKey][] = $unitKey;
                    $groupsByUnit[$unitKey] = [$groupId => true];
                    $firstByUnit[$unitKey] = $lesson->starts_at->getTimestamp();
                }

                continue;
            }

            if ($slot === null || $splitKey === null) {
                continue;
            }

            $unitKey = null;

            foreach ($unitsBySplitKey[$splitKey] ?? [] as $candidate) {
                if (isset($groupsByUnit[$candidate][$groupId])) {
                    continue;
                }

                // Uma lição anterior ao início do tempo desta aula não é dela:
                // um T2 criado em novembro não fica com a lição de setembro.
                if ($slot['from'] !== null && $firstByUnit[$candidate] < $slot['from']) {
                    continue;
                }

                $unitKey = $candidate;

                break;
            }

            if ($unitKey === null) {
                $unitKey = (string) Str::ulid();
                $unitsBySplitKey[$splitKey][] = $unitKey;
                $groupsByUnit[$unitKey] = [];
                $firstByUnit[$unitKey] = $lesson->starts_at->getTimestamp();
            }

            $groupsByUnit[$unitKey][$groupId] = true;
            $lesson->lesson_unit_key = $unitKey;

            if ($lesson->exists) {
                $links[(int) $lesson->getKey()] = $unitKey;
            }
        }

        return $links;
    }

    /**
     * @return array<int, array{key: string|null, from: int|null}>
     */
    private function slotKeys(int $classId): array
    {
        $keys = [];

        foreach (RecurringLessonSlot::query()->where('class_id', $classId)->get() as $slot) {
            $from = $slot->starts_on ?? $slot->created_at;

            $keys[(int) $slot->getKey()] = [
                'key' => $slot->split_lesson_key,
                'from' => $from?->copy()->setTimezone('Europe/Lisbon')->startOfDay()->getTimestamp(),
            ];
        }

        return $keys;
    }

    /**
     * A derivação (ver o docblock da classe). Devolve uma entrada por unidade, na
     * ordem da cadeia, com o intervalo FINAL de cada uma.
     *
     * 1. As unidades FIXAS (manuais e, se `$historyFixed`, históricas) guardam o
     *    número que têm, e esse número fica OCUPADO. Um fim legado («12–13»)
     *    guarda-se no registo, mas não ocupa nada (0.172.0).
     * 2. As livres ANTES da primeira fixa numeram-se PARA TRÁS a partir dela: a
     *    k-ésima a contar da fixa recebe `início − k`. É o que faz com que, com a
     *    primeira aula registada na Lição 11, abrir uma semana anterior sugira
     *    10, 9… em vez de 1, 2.
     * 3. As restantes numeram-se PARA A FRENTE: o número da unidade numerada
     *    anterior + 1, saltando os números ocupados; se esse número já alcança a
     *    próxima fixa, não há lugar.
     * 4. Sem lugar, a unidade fica sem número — EXCETO se já tinha um número
     *    guardado que está livre: esse mantém-se. As turmas reais têm números do
     *    antigo «maior + 1», e a derivação nova não lhos tira.
     *
     * @param  Collection<int, Lesson>  $lessons  ordenadas por starts_at, id
     * @return list<array{lessons: list<Lesson>, kind: string, start: int|null, end: int|null}>
     */
    private function project(Collection $lessons, bool $historyFixed = true): array
    {
        $units = $this->units($lessons);
        $kinds = [];
        $starts = [];
        $ends = [];
        $occupied = [];
        $fixed = [];

        foreach ($units as $index => $unit) {
            $interval = $this->fixedInterval($unit, $historyFixed);

            $kinds[$index] = $interval[0] ?? self::FREE;
            $starts[$index] = $interval[1] ?? null;
            $ends[$index] = $interval[2] ?? null;

            if ($interval === null) {
                continue;
            }

            $fixed[] = $index;
            // UMA AULA, UM NÚMERO: só o início. Era aqui que um intervalo
            // «12–13» ocupava também a 13 e mandava a aula seguinte para a 14.
            $occupied[$interval[1]] = true;
        }

        // Para cada posição, a primeira unidade fixa a seguir: a «próxima fixa».
        $nextFixed = [];
        $upcoming = null;

        for ($index = count($units) - 1; $index >= 0; $index--) {
            $nextFixed[$index] = $upcoming;

            if ($kinds[$index] !== self::FREE) {
                $upcoming = $index;
            }
        }

        $first = $fixed[0] ?? null;

        // A corrida inicial: para trás a partir da primeira fixa.
        if ($first !== null) {
            $anchor = (int) $starts[$first];

            for ($distance = 1; $first - $distance >= 0; $distance++) {
                $index = $first - $distance;
                $candidate = $anchor - $distance;

                $starts[$index] = $this->assign(
                    $units[$index],
                    $candidate >= 1 && ! isset($occupied[$candidate]) ? $candidate : null,
                    $occupied,
                );
            }
        }

        // O resto, para a frente.
        $previous = 0;

        for ($index = $first ?? 0; $index < count($units); $index++) {
            if ($kinds[$index] !== self::FREE) {
                // O número da fixa, nunca o fim de um intervalo legado.
                $previous = (int) $starts[$index];

                continue;
            }

            $candidate = $previous + 1;

            while (isset($occupied[$candidate])) {
                $candidate++;
            }

            $upcomingIndex = $nextFixed[$index];

            if ($upcomingIndex !== null && $candidate >= (int) $starts[$upcomingIndex]) {
                $candidate = null;
            }

            $number = $this->assign($units[$index], $candidate, $occupied);
            $starts[$index] = $number;

            // Um número MANTIDO por falta de lugar pode ser antigo e menor (o
            // «maior + 1» de antes, ou um número de antes de uma correção). Não
            // faz recuar a contagem: a livre seguinte no mesmo intervalo também
            // não tem lugar, em vez de ganhar um número fora de ordem.
            if ($number !== null && $number > $previous) {
                $previous = $number;
            }
        }

        $records = [];

        foreach ($units as $index => $unit) {
            $records[] = ['lessons' => $unit, 'kind' => $kinds[$index], 'start' => $starts[$index], 'end' => $ends[$index]];
        }

        return $records;
    }

    /**
     * Atribui o número a uma unidade livre e marca-o como ocupado. Sem
     * candidato, a unidade MANTÉM o número que já tinha, se tinha um e ele está
     * livre; senão fica sem número.
     *
     * @param  list<Lesson>  $unit
     * @param  array<int, true>  $occupied
     */
    private function assign(array $unit, ?int $candidate, array &$occupied): ?int
    {
        if ($candidate === null) {
            foreach ($unit as $lesson) {
                if ($lesson->lesson_number === null) {
                    continue;
                }

                if (! isset($occupied[$lesson->lesson_number])) {
                    $candidate = $lesson->lesson_number;
                }

                break;
            }
        }

        if ($candidate !== null) {
            $occupied[$candidate] = true;
        }

        return $candidate;
    }

    /**
     * O intervalo fixo de uma unidade, se tiver um: MANUAL (alguma aula com o
     * número indicado pelo professor) ou, se o histórico conta como fixo,
     * HISTÓRICO (alguma aula fechada e com número). Nunca derivado.
     *
     * @param  list<Lesson>  $unit
     * @return array{0: string, 1: int, 2: int|null}|null
     */
    private function fixedInterval(array $unit, bool $historyFixed): ?array
    {
        $manual = $this->manualInterval($unit);

        if ($manual !== null) {
            return [self::MANUAL, $manual[0], $manual[1]];
        }

        if ($historyFixed) {
            foreach ($unit as $lesson) {
                if ($lesson->isClosedAndNumbered() && $lesson->lesson_number !== null) {
                    return [self::HISTORY, $lesson->lesson_number, $this->normalizedEnd($lesson->lesson_number, $lesson->lesson_number_end)];
                }
            }
        }

        return null;
    }

    /**
     * @param  list<Lesson>  $unit
     * @return array{0: int, 1: int|null}|null
     */
    private function manualInterval(array $unit): ?array
    {
        foreach ($unit as $lesson) {
            if ($lesson->lesson_number_manual && $lesson->lesson_number !== null) {
                return [$lesson->lesson_number, $this->normalizedEnd($lesson->lesson_number, $lesson->lesson_number_end)];
            }
        }

        return null;
    }

    /**
     * Um fim só existe se for posterior ao início — e, por defesa, dentro de um
     * intervalo razoável: um dado estragado não pode fazer o ciclo dos números
     * ocupados percorrer milhões de inteiros.
     */
    private function normalizedEnd(int $start, ?int $end): ?int
    {
        return $end !== null && $end > $start && $end - $start <= self::MAX_SPAN ? $end : null;
    }

    /**
     * O número POSICIONAL de cada aula gravada — a regra de antes da 0.169.0, com
     * as unidades manuais como pontos de recomeço: começa em 1, cada unidade não
     * manual ocupa uma posição, e uma manual faz a contagem seguir do seu número
     * + 1 (nunca do fim de um intervalo legado: a mesma regra de `project()`).
     * Só serve a guarda do histórico: sem fixas por histórico, sem nulos e sem
     * corrida para trás. Aulas de unidades manuais não têm posicional (NULL).
     *
     * @param  Collection<int, Lesson>  $lessons  ordenadas por starts_at, id
     * @return array<int, int|null>
     */
    private function positions(Collection $lessons): array
    {
        $positions = [];
        $next = 1;

        foreach ($this->units($lessons) as $unit) {
            $manual = $this->manualInterval($unit);

            if ($manual !== null) {
                $next = $manual[0] + 1;

                foreach ($unit as $lesson) {
                    if ($lesson->exists) {
                        $positions[(int) $lesson->getKey()] = null;
                    }
                }

                continue;
            }

            foreach ($unit as $lesson) {
                if ($lesson->exists) {
                    $positions[(int) $lesson->getKey()] = $next;
                }
            }

            $next++;
        }

        return $positions;
    }

    /**
     * @param  Collection<int, Lesson>  $lessons  ordenadas por starts_at, id
     * @param  array<int, int>|null  $snapshot  ver `resequence()`
     *
     * @throws ValidationException
     */
    private function guardTaughtHistory(Collection $lessons, ?array $snapshot): void
    {
        $positions = $this->positions($lessons);

        foreach ($lessons as $lesson) {
            if (! $lesson->exists) {
                continue;
            }

            $id = (int) $lesson->getKey();
            $position = $positions[$id] ?? null;

            // Uma aula lecionada ainda sem número está a receber o primeiro,
            // não a mudar de número.
            $guarded = $snapshot === null
                ? $lesson->isClosedAndNumbered() && $lesson->lesson_number !== null
                : isset($snapshot[$id]);

            if ($guarded && $position !== null && $lesson->lesson_number !== $position) {
                throw ValidationException::withMessages([
                    'lesson_number' => __(
                        'Esta operação mudaria o número da aula de :date, que já foi lecionada (Lição :from → Lição :to). O histórico não é renumerado.',
                        [
                            'date' => $lesson->starts_at->setTimezone('Europe/Lisbon')->format('d/m/Y'),
                            'from' => (string) $lesson->lesson_number,
                            'to' => (string) $position,
                        ],
                    ),
                ]);
            }
        }
    }

    /**
     * As escritas que a derivação pede: por aula gravada, o triplo (início, fim,
     * manual) quando difere do que está gravado.
     *
     *  - unidade MANUAL: todas as aulas recebem o número e a marca (um T2 que
     *    se junte depois herda-os);
     *  - unidade HISTÓRICA: só as aulas ainda abertas (ou fechadas sem número);
     *    uma aula fechada com número nunca é escrita;
     *  - unidade LIVRE: todas recebem o número derivado, sem fim e sem marca.
     *
     * UM FIM LEGADO NUNCA SE COPIA (0.172.0): fica só na aula que o tem, e só
     * enquanto ela mantiver o número. Um T2 que se junte a uma unidade «12–13»
     * fica com a Lição 12, não com um intervalo novo.
     *
     * @param  list<array{lessons: list<Lesson>, kind: string, start: int|null, end: int|null}>  $records
     * @param  array<int, array{number: int|null, end: int|null, manual: bool}>  $originals  o gravado, quando o modelo em memória foi alterado para a projeção
     * @return array<int, array{lesson: Lesson, from: int|null, from_end: int|null, from_manual: bool, to: int|null, to_end: int|null, manual: bool}>
     */
    private function changesFor(array $records, array $originals = []): array
    {
        $changes = [];

        foreach ($records as $record) {
            foreach ($record['lessons'] as $lesson) {
                if (! $lesson->exists) {
                    continue;
                }

                if ($record['kind'] === self::HISTORY && $lesson->isClosed() && $lesson->lesson_number !== null) {
                    continue;
                }

                $id = (int) $lesson->getKey();
                $from = $originals[$id] ?? [
                    'number' => $lesson->lesson_number,
                    'end' => $lesson->lesson_number_end,
                    'manual' => (bool) $lesson->lesson_number_manual,
                ];
                $manual = $record['kind'] === self::MANUAL;
                // O fim da PRÓPRIA aula (em memória: o de uma aula cujo número o
                // professor acabou de indicar já é NULL), e só se o número fica.
                $toEnd = $record['kind'] !== self::FREE && $record['start'] !== null && $lesson->lesson_number === $record['start']
                    ? $this->normalizedEnd($record['start'], $lesson->lesson_number_end)
                    : null;

                if ($from['number'] === $record['start'] && $from['end'] === $toEnd && $from['manual'] === $manual) {
                    continue;
                }

                $changes[$id] = [
                    'lesson' => $lesson,
                    'from' => $from['number'],
                    'from_end' => $from['end'],
                    'from_manual' => $from['manual'],
                    'to' => $record['start'],
                    'to_end' => $toEnd,
                    'manual' => $manual,
                ];
            }
        }

        return $changes;
    }

    /**
     * O que está GRAVADO em cada aula da sequência, antes de qualquer alteração
     * em memória.
     *
     * @param  Collection<int, Lesson>  $lessons
     * @return array<int, array{number: int|null, end: int|null, manual: bool}>
     */
    private function triples(Collection $lessons): array
    {
        $triples = [];

        foreach ($lessons as $lesson) {
            $triples[(int) $lesson->getKey()] = [
                'number' => $lesson->lesson_number,
                'end' => $lesson->lesson_number_end,
                'manual' => (bool) $lesson->lesson_number_manual,
            ];
        }

        return $triples;
    }

    /**
     * As unidades de lição, pela ordem da sua primeira aula.
     *
     * @param  Collection<int, Lesson>  $lessons  ordenadas por starts_at, id
     * @return list<list<Lesson>>
     */
    private function units(Collection $lessons): array
    {
        /** @var array<string, list<Lesson>> $units */
        $units = [];

        foreach ($lessons as $lesson) {
            $key = $lesson->class_group_id !== null && $lesson->lesson_unit_key !== null
                ? 'unit:'.$lesson->lesson_unit_key
                : 'lesson:'.($lesson->getKey() ?? 'new-'.spl_object_id($lesson));

            // Um array PHP preserva a ordem de inserção: a unidade ocupa o seu
            // lugar quando aparece a PRIMEIRA aula dela.
            $units[$key][] = $lesson;
        }

        return array_values($units);
    }

    /**
     * @param  array<int, string>  $links
     */
    private function writeLinks(array $links): void
    {
        foreach ($links as $lessonId => $unitKey) {
            DB::table('lessons')->where('id', $lessonId)->whereNull('lesson_unit_key')->update(['lesson_unit_key' => $unitKey]);
        }
    }

    /**
     * COM A NUMERAÇÃO CONGELADA (`LAPIS_LESSON_NUMBERING_FROZEN`), o triplo
     * (`lesson_number`, `lesson_number_end`, `lesson_number_manual`) de uma aula
     * que JÁ TEM número nunca é alterado: só se escrevem as aulas cujo número
     * gravado é NULL, que recebem a sugestão. É a contenção da recuperação
     * (docs/lesson-numbering-recovery.md).
     *
     * @template T of array{from?: int|null, to: int|null, to_end: int|null, manual: bool}
     *
     * @param  array<int, T>  $changes
     * @return array<int, T>
     */
    private function writable(array $changes): array
    {
        if (! LessonNumberingFreeze::frozen()) {
            return $changes;
        }

        return array_filter($changes, fn (array $change): bool => ($change['from'] ?? null) === null);
    }

    /**
     * @param  array<int, array{from?: int|null, to: int|null, to_end: int|null, manual: bool}>  $changes
     */
    private function writeNumbers(array $changes): void
    {
        foreach ($this->writable($changes) as $lessonId => $change) {
            DB::table('lessons')->where('id', $lessonId)->update([
                'lesson_number' => $change['to'],
                'lesson_number_end' => $change['to_end'],
                'lesson_number_manual' => $change['manual'],
            ]);
        }
    }

    /**
     * @return Collection<int, Lesson>
     */
    private function sequence(int $classId): Collection
    {
        // UMA AUSÊNCIA DO PROFESSOR NÃO É LIÇÃO (0.146.0): fica fora da
        // sequência, sem número e sem unidade.
        return Lesson::query()
            ->where('class_id', $classId)
            ->where(fn ($query) => $query->whereNull('outcome')->orWhere('outcome', '!=', LessonOutcome::TeacherAbsent->value))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
    }
}
