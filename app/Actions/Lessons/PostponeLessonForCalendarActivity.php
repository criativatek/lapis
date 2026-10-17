<?php

namespace App\Actions\Lessons;

use App\Models\CalendarEvent;
use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Lessons\LessonDayEvents;
use App\Services\Lessons\ShiftLessonPlanning;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Registar e adiar a preparação» (0.171.0) — a aula é ocupada por uma
 * atividade do calendário do professor (uma visita de estudo, um teste de
 * outra disciplina, uma reunião de turma…): fica registada como «Turma em
 * outras atividades letivas» e a preparação que tinha avança UMA aula, pela
 * mesma cadeia de sempre (ShiftLessonPlanning) — a que o fecho rápido já usa.
 *
 * Esta classe não reimplementa o deslocamento: chama RecordLessonOutcome, que
 * é a regra única, e SÓ lê o traço do que ele fez para o dizer ao professor.
 *
 * UM SÓ PLANO, COMO EM ApplyLessonSequence. `preview()` e `execute()` correm o
 * mesmo `plan()`; a pré-visualização corre-o numa transação SEMPRE revertida
 * (para saber o destino é preciso deslocar de verdade e, às vezes, materializar
 * a próxima ocorrência do horário), e a execução corre-o e só então compara o
 * `plan_token` — se a comparação falhar, a exceção reverte tudo o que o plano
 * acabou de escrever.
 *
 * O `plan_token` é a impressão digital do EFEITO, e não do pedido: aula e versão
 * do seu sumário, acontecimento (ULID e `updated_at`), nota e, para cada
 * deslocamento, a origem, o destino (o ULID, ou `new:`+início quando
 * a aula só nasce com a operação), a versão do sumário de cada lado ANTES de
 * escrever e o hash do que viaja; mais a pendência, as renumerações e os
 * rascunhos descartados (QUAIS, pelas inscrições, e não só quantos). Tem de dar
 * o mesmo na pré-visualização e na execução quando nada mudou — e muda se o
 * sumário de qualquer aula da cadeia mudar.
 *
 * O QUE FICA NA AULA É O TÍTULO, no resultado que já existe: «Turma em outras
 * atividades letivas» com o título do acontecimento na nota curta
 * (`outcome_note`), que a página da aula mostra ao reabrir e o registo da turma
 * lista. As NOTAS do acontecimento nunca são copiadas para ali — podem ter
 * dados pessoais («autorizações do João») e o registo da turma sai para fora da
 * sala; continuam completas no calendário do professor.
 *
 * NENHUMA LINHA DO SUMÁRIO É RETIRADA. Todo o texto da preparação viaja inteiro
 * com ela: para a aula seguinte ou para a pendência.
 *
 * UMA LINHA AMBÍGUA BLOQUEIA A OPERAÇÃO, ANTES DE QUALQUER ESCRITA. «Incluir no
 * sumário» só acrescenta texto ao editor, e nada guarda de onde veio cada
 * linha: uma linha igual à que o botão gera para este acontecimento (aparados
 * os espaços nas pontas) tanto pode ter sido acrescentada pelo botão como
 * escrita pelo professor. Retirá-la poderia apagar texto do professor;
 * deixá-la avançar levaria a atividade para a preparação da aula seguinte.
 * Não se adivinha: a pré-visualização e a execução recusam com a mesma
 * mensagem, sem tocar em nada, e o professor decide — apaga ou altera essa
 * linha, guarda e tenta de novo. Uma linha que difira da gerada (basta um
 * carácter no meio) é texto do professor e não bloqueia: viaja com o resto.
 */
class PostponeLessonForCalendarActivity
{
    /** O excerto de cada preparação na pré-visualização (nunca notas privadas). */
    private const EXCERPT_LENGTH = 140;

    public function __construct(
        protected RecordLessonOutcome $recordLessonOutcome,
        protected ShiftLessonPlanning $planning,
        protected AuditLog $audit,
    ) {}

    /**
     * O que a ação FARIA, sem gravar nada.
     *
     * @return array<string, mixed>
     */
    public function preview(Lesson $lesson, CalendarEvent $event, User $actor): array
    {
        DB::beginTransaction();

        try {
            // Montada ANTES da reversão: depois dela, a aula que o plano
            // acabou de materializar já não existe.
            return $this->plan($lesson, $event, $actor)['effect'];
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @return array<string, mixed> o mesmo efeito da pré-visualização, agora gravado
     */
    public function execute(Lesson $lesson, CalendarEvent $event, User $actor, string $planToken, bool $confirmCascade): array
    {
        return DB::transaction(function () use ($lesson, $event, $actor, $planToken, $confirmCascade): array {
            ['effect' => $effect, 'locked' => $locked] = $this->plan($lesson, $event, $actor);

            // Todas as recusas AQUI, de dentro da transação: a exceção desfaz
            // o que o plano acabou de escrever.
            if (! hash_equals($effect['plan_token'], $planToken)) {
                throw ValidationException::withMessages([
                    'plan_token' => __('A aula ou as aulas seguintes mudaram desde a pré-visualização. Revê e confirma de novo.'),
                ]);
            }

            if ($effect['requires_cascade_confirmation'] && ! $confirmCascade) {
                throw ValidationException::withMessages([
                    'confirm_cascade' => __('Confirma que as preparações avançam uma aula antes de registar.'),
                ]);
            }

            // SÓ IDS E CONTAGENS: nunca o texto da preparação nem o título.
            $this->audit->record(
                'lesson.postponed_for_calendar_activity',
                $locked,
                $actor,
                'Preparação adiada por uma atividade do calendário.',
                [
                    'calendar_event_id' => $event->getKey(),
                    'moves' => count($effect['moves']),
                    'pending' => $effect['pending'] !== null,
                    'renumbered_lessons' => count($effect['renumbered']),
                    'discarded_attendance_drafts' => $effect['discarded_attendance_drafts'],
                ],
            );

            return $effect;
        });
    }

    /**
     * Escreve (dentro da transação de quem chama) e descreve o que escreveu.
     *
     * @return array{effect: array<string, mixed>, locked: Lesson}
     */
    private function plan(Lesson $lesson, CalendarEvent $event, User $actor): array
    {
        SchoolClass::query()->whereKey($lesson->class_id)->lockForUpdate()->first();

        /** @var Lesson $locked */
        $locked = Lesson::query()->lockForUpdate()->findOrFail($lesson->getKey());

        // O acontecimento RELIDO E BLOQUEADO dentro da transação: o título que fica
        // na aula e o `updated_at` que entra no token são os do momento em que se
        // escreve, e não os de quando o pedido o validou.
        /** @var CalendarEvent $event */
        $event = CalendarEvent::query()->lockForUpdate()->findOrFail($event->getKey());

        // Antes de QUALQUER escrita: uma linha cuja origem não se distingue
        // recusa tudo, na pré-visualização e na execução.
        $this->refuseAmbiguousActivityLine($locked, LessonDayEvents::summaryLine($event));

        // O «ANTES»: a versão do sumário da aula e de cada aula aberta seguinte
        // (`openLessonsAfter` bloqueia-as) e os números da turma.
        /** @var array<int, int> $versionsBefore */
        $versionsBefore = [(int) $locked->getKey() => (int) $locked->summary_version];

        foreach ($this->planning->openLessonsAfter($locked) as $following) {
            $versionsBefore[(int) $following->getKey()] = (int) $following->summary_version;
        }

        $numbersBefore = $this->lessonNumbers($locked->class_id);

        // Os rascunhos de falta que o resultado vai descartar, pelas INSCRIÇÕES
        // (nunca nomes): a confirmação tem de descartar os mesmos que a
        // pré-visualização contou, e não apenas o mesmo número deles.
        /** @var list<int> $draftEnrollments */
        $draftEnrollments = $locked->attendances()->orderBy('enrollment_id')->pluck('enrollment_id')
            ->map(fn ($id): int => (int) $id)->values()->all();

        // A nota da atividade é o título, numa linha: `cleanNote` corta a 160.
        $note = LessonDayEvents::summaryLine(['title' => $event->title]);

        $result = $this->recordLessonOutcome->perform($locked, LessonOutcome::ClassExternalActivity, $actor, null, $note);
        $trace = $result['planning'];

        $moves = [];
        $tokenMoves = [];

        foreach ($trace['moves'] as $move) {
            /** @var Lesson $from */
            $from = $move['from'];
            /** @var Lesson $to */
            $to = $move['to'];
            $destination = Lesson::query()->findOrFail((int) $to->getKey());

            $moves[] = [
                'from' => ['ulid' => $from->ulid, 'starts_at' => $from->starts_at->toIso8601String()],
                'to' => [
                    // Uma aula que o plano materializou não existirá depois da
                    // pré-visualização: o ULID dela não serve de nada ao ecrã.
                    'ulid' => $move['materialized'] ? null : $destination->ulid,
                    'starts_at' => $destination->starts_at->toIso8601String(),
                    'ends_at' => $destination->ends_at?->toIso8601String(),
                    'lesson_number' => $destination->lesson_number,
                    'lesson_number_end' => $destination->lesson_number_end,
                    'lesson_number_label' => Lesson::formatLessonNumber($destination->lesson_number, $destination->lesson_number_end),
                ],
                'created_from_timetable' => $move['materialized'],
                'excerpt' => $this->excerpt($move['values']),
                'fields' => $this->fields($move['values']),
            ];

            $tokenMoves[] = [
                $from->ulid,
                $versionsBefore[(int) $from->getKey()] ?? null,
                $move['materialized'] ? 'new:'.$destination->starts_at->format('Y-m-d H:i:s') : $destination->ulid,
                $move['materialized'] ? null : ($versionsBefore[(int) $to->getKey()] ?? null),
                $this->valuesHash($move['values']),
            ];
        }

        /** @var Lesson|null $pendingOn */
        $pendingOn = $trace['pending_on'];
        $pending = null;

        if ($pendingOn !== null) {
            $pending = [
                'on' => ['ulid' => $pendingOn->ulid, 'starts_at' => $pendingOn->starts_at->toIso8601String()],
                'on_this_lesson' => $pendingOn->getKey() === $locked->getKey(),
                'excerpt' => $this->excerpt($trace['pending_values'] ?? []),
                'fields' => $this->fields($trace['pending_values'] ?? []),
            ];
        }

        $renumbered = $this->renumbered($locked->class_id, $numbersBefore);

        // Quantas preparações mudam de lugar: as que avançam e a que fica como
        // pendência. «A → B, e a de B fica pendente» são DUAS preparações
        // deslocadas, embora só haja um deslocamento — e pedem confirmação.
        $displaced = count($moves) + ($pending === null ? 0 : 1);

        $effect = [
            'lesson' => ['ulid' => $locked->ulid, 'starts_at' => $locked->starts_at->toIso8601String()],
            'event' => ['ulid' => $event->ulid, 'title' => $event->title],
            'outcome_label' => LessonOutcome::ClassExternalActivity->label(),
            'note' => $result['lesson']->outcome_note,
            'moves' => $moves,
            'pending' => $pending,
            'nothing_to_move' => $moves === [] && $pending === null,
            'discarded_attendance_drafts' => (int) $result['dropped_attendance_drafts'],
            'renumbered' => $renumbered,
            'displaced_preparations' => $displaced,
            'requires_cascade_confirmation' => $displaced > 1,
            'message' => __('Atividade registada.').' '.ShiftLessonPlanning::destinationSentence($trace, $locked),
        ];

        $effect['plan_token'] = hash('sha256', (string) json_encode([
            'lesson' => [$locked->ulid, $versionsBefore[(int) $locked->getKey()]],
            'event' => [$event->ulid, $event->updated_at?->toJSON()],
            'note' => $effect['note'],
            'moves' => $tokenMoves,
            'pending' => $pendingOn === null ? null : [$pendingOn->ulid, $versionsBefore[(int) $pendingOn->getKey()] ?? null, $this->valuesHash($trace['pending_values'] ?? [])],
            'renumbered' => array_map(fn (array $row): array => [$row['ulid'], $row['from'], $row['to']], $renumbered),
            'drafts' => [$effect['discarded_attendance_drafts'], $draftEnrollments],
        ]));

        return ['effect' => $effect, 'locked' => $locked];
    }

    /**
     * A REGRA DO BLOQUEIO: o sumário (`content`) da aula tem uma linha que,
     * aparados os espaços nas pontas (também os não ASCII, como o espaço
     * inseparável), é igual à que «Incluir no sumário» gera para este
     * acontecimento. Nada no sistema diz se essa linha veio do botão ou foi
     * escrita pelo professor, e a operação não pode retirá-la (apagaria texto do
     * professor) nem levá-la (a aula seguinte ficaria com a atividade). Recusa,
     * só a ler — quem chama ainda não escreveu nada.
     */
    private function refuseAmbiguousActivityLine(Lesson $lesson, string $activityLine): void
    {
        if ($activityLine === '') {
            return;
        }

        $content = (string) $lesson->summary()->value('content');

        foreach (preg_split('/\R/u', $content) ?: [] as $line) {
            if ((string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $line) !== $activityLine) {
                continue;
            }

            throw ValidationException::withMessages([
                'activity_line' => __(
                    'O sumário desta aula tem a linha «:line», igual à que «Incluir no sumário» acrescenta. Não é possível saber se foi escrita por ti ou acrescentada automaticamente, por isso a preparação não foi adiada e nada foi alterado. Para adiar, apaga ou altera essa linha no sumário, guarda e tenta de novo.',
                    ['line' => $activityLine],
                ),
            ]);
        }
    }

    /**
     * @return array<int, int|null> lesson_id => número
     */
    private function lessonNumbers(int $classId): array
    {
        /** @var array<int, int|null> $numbers */
        $numbers = Lesson::query()->where('class_id', $classId)->pluck('lesson_number', 'id')->all();

        return $numbers;
    }

    /**
     * As aulas que JÁ EXISTIAM e mudaram de número, com de/para.
     *
     * @param  array<int, int|null>  $before
     * @return list<array{ulid: string, starts_at: string, from: int|null, to: int|null}>
     */
    private function renumbered(int $classId, array $before): array
    {
        $changed = [];

        foreach (Lesson::query()->where('class_id', $classId)->orderBy('starts_at')->orderBy('id')->get() as $lesson) {
            $id = (int) $lesson->getKey();

            if (array_key_exists($id, $before) && $before[$id] !== $lesson->lesson_number) {
                $changed[] = [
                    'ulid' => $lesson->ulid,
                    'starts_at' => $lesson->starts_at->toIso8601String(),
                    'from' => $before[$id],
                    'to' => $lesson->lesson_number,
                ];
            }
        }

        return $changed;
    }

    /**
     * O início do conteúdo — NUNCA as notas privadas.
     *
     * @param  array<string, string|int|null>  $values
     */
    private function excerpt(array $values): ?string
    {
        $content = $values['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            return null;
        }

        $flat = (string) preg_replace('/\s+/u', ' ', trim($content));

        return mb_strlen($flat) > self::EXCERPT_LENGTH ? rtrim(mb_substr($flat, 0, self::EXCERPT_LENGTH - 1)).'…' : $flat;
    }

    /**
     * Quais dos quatro campos viajam.
     *
     * @param  array<string, string|int|null>  $values
     * @return list<string>
     */
    private function fields(array $values): array
    {
        return array_values(array_filter(
            ['content', 'resources', 'homework', 'private_notes'],
            fn (string $field): bool => ($values[$field] ?? null) !== null,
        ));
    }

    /**
     * @param  array<string, string|int|null>  $values
     */
    private function valuesHash(array $values): string
    {
        ksort($values);

        return hash('sha256', (string) json_encode($values));
    }
}
