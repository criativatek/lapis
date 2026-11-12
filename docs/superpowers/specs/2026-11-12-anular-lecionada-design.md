# Anular «Lecionada» — a aula não se realizou — desenho

**Data:** 2026-11-12 · **Estado:** aprovado em conversa, por rever no ficheiro · **Versão alvo:** 0.194.0

## 1. Problema

Um professor marcou uma aula como «Lecionada» (sumário escrito, Lição 34,
assiduidade consolidada «sem faltas») e a aula não se realizou: mau tempo. A
aplicação recusa, de propósito, corrigir o resultado a partir de «Lecionada»
(`RectifyLessonOutcome`: «Uma aula lecionada tem sumário, faltas e número de
lição: não se corrige por aqui») e o registo retroativo de «Aula não realizada»
só aceita aulas vazias. Não existe nenhuma forma, nem para o professor nem para
o suporte, de desfazer um «Lecionada» dado por engano. O serviço letivo conta
uma aula que não houve e a numeração segue em cima dela.

## 2. Decisões tomadas com o utilizador

- **Sumário:** passa à aula seguinte quando possível (sem aulas fechadas a
  seguir); com aulas fechadas a seguir, sai da aula e fica só no histórico da
  correção, legível pelo professor.
- **Assiduidade:** apaga-se sempre. «Não há aula, não há faltas.» Sem histórico
  das linhas apagadas.
- **Quem:** o professor que fechou a aula, a qualquer momento; e a direção da
  organização **institucional** que detém a turma (a competência de
  «Retificações»), a partir da área «Retificações», mesmo sem lecionar a turma
  e mesmo com o professor ainda na turma. Nunca um observador; nunca numa
  organização pessoal.
- **Numeração:** com aulas fechadas a seguir, a aula perde o número e fica a
  lacuna; nenhuma aula lecionada muda de número (o professor renumera à mão se
  quiser). Sem aulas fechadas a seguir, as abertas renumeram-se sem lacuna. É a
  regra que o registo retroativo já aplica.

## 3. Âmbito

**Dentro:** a ação de anulação no servidor; a entrada na página da aula (para
quem a fechou); a lista «Aulas fechadas» por turma na área «Retificações» com a
ação (para a direção); histórico e auditoria; testes.

**Fora:** anular aulas que partilham a lição com outro grupo (T1/T2 com
`lesson_unit_key`) — recusa-se com a razão; a ação na vista semanal; notificar o
professor por email quando é a direção a anular (o histórico da aula diz-o);
qualquer alteração ao registo normal de «Aula não realizada».

## 4. Abordagem escolhida: ação própria

Entre (A) uma ação nova `AnnulTaughtLesson` que reutiliza as peças existentes,
(B) alargar `RecordLessonOutcome` a aulas lecionadas e (C) um «reabrir aula»
genérico, escolheu-se **A**. (B) acrescentaria ramos à classe mais delicada do
módulo (numeração, grupos, retroativo); (C) não resolve o caso real, porque o
registo retroativo recusa aulas com sumário.

## 5. A ação `AnnulTaughtLesson`

`App\Actions\Lessons\AnnulTaughtLesson::execute(Lesson $lesson, User $actor, LessonNotHeldCause $cause, string $motive, ?string $causeNote = null, ?TeacherStrikeSituation $situation = null): array`

Devolve `['lesson' => Lesson, 'planning' => array (de ShiftLessonPlanning ou null), 'summary_kept_in_history' => bool, 'renumbered' => array]`.

### 5.1 Guardas (todas dentro da transação, turma e aula bloqueadas)

| Recusa | Mensagem (chave `outcome`) |
|---|---|
| funcionalidade `lapis.lesson_not_held_enabled` desligada | 403 pela policy |
| a aula não está lecionada (`isTaught()` falso) | «Só uma aula marcada como lecionada pode ser anulada.» |
| `lesson_unit_key` não nulo | «Esta aula partilha a lição com outro grupo: ainda não se anula por aqui.» |
| causa `other` sem descrição, `strike` sem a situação válida, etc. | as de `RecordLessonOutcome::guardDetails` (reutilizadas) |
| motivo vazio ou > 500 caracteres | validação do pedido |

A policy `LessonPolicy::annulTaught(User, Lesson)`: funcionalidade ligada **e**
(`outcome_recorded_by` = o utilizador e não observador da turma **ou**
`Gate::allows('reviewRectifications', $lesson->schoolClass)`).

### 5.2 Efeitos, por ordem

1. `$closedAfter = ShiftLessonPlanning::closedLessonAfter($locked)`.
2. **Assiduidade:** `lesson_attendances` da aula apagadas; `attendance_recorded_at`
   e `attendance_recorded_by` a null.
3. **Sumário e planeamento:**
   - sem `$closedAfter`: `ShiftLessonPlanning::from($locked, $actor)` — o
     sumário, recursos, TPC e notas descem para a aula seguinte (materializa se
     preciso; pendência se o ano acabou), exatamente como no registo normal;
     `summary_kept_in_history = false`;
   - com `$closedAfter`: o texto dos quatro campos do sumário é copiado para o
     histórico (§6) e a linha do sumário é apagada **pelo modelo**
     (`$locked->summary()->first()?->delete()`, para subir `summary_version`);
     o planeamento pendente da aula (`lesson_plans`), se existir, fica onde está;
     `summary_kept_in_history = true`.
4. **Resultado:** `outcome = not_held`, `outcome_cause`, `outcome_cause_note`,
   `outcome_teacher_situation` (só greve), `outcome_reason = null`,
   `outcome_note = null`, `outcome_recorded_at = now`, `outcome_recorded_by =
   actor`; `status = preparation`.
5. **Número:** guarda `from_number`; se `lesson_number_manual` e número não
   nulo, o número manual passa à próxima unidade livre
   (`LessonNumbering::handOverManualNumber`, como no registo normal); depois
   `lesson_number`, `lesson_number_end` a null e `lesson_number_manual` a false.
6. **Renumeração:** com `$closedAfter`, `LessonNumbering::resequenceAcceptingGaps`
   (as fechadas não mudam, fica a lacuna); sem, `resequence` com a fotografia
   da guarda tirada no início (`guardSnapshot`), ou `numberMaterializedLessons`
   se a descida do planeamento materializou uma ocorrência — a mesma escolha de
   `RecordLessonOutcome::renumber`.
7. **Histórico** (§6) e **auditoria** (§7).
8. Devolve a aula atualizada.

## 6. Histórico: `lesson_outcome_rectifications`

Uma linha com `kind = annulment` (constante nova `KIND_ANNULMENT`), `previous`
com os seis campos de sempre (outcome `taught`, restantes null) **mais**
`lesson_number` (o número que tinha) e, quando `summary_kept_in_history`,
`summary_content`, `summary_resources`, `summary_homework`,
`summary_private_notes`; `current` com os seis campos do novo resultado;
`motive`; `rectified_by` = actor. Nenhuma coluna nova: `previous` e `current`
são JSON.

**Leitura** (`LessonOutcomeRectificationView`): o professor da aula vê «Marcação
de lecionada anulada [pela direção | pelo próprio] — tinha o n.º 34 —
motivo: … — sumário removido: <texto>»; a direção, na sua lista, vê a mesma
linha **sem** o texto do sumário (os campos `summary_*` só chegam a quem passa
em `LessonOutcomeDetails::mayRead`). «Pela direção» deriva de `rectified_by`
não ser o `outcome_recorded_by` anterior.

## 7. Auditoria

Evento `lesson.taught_annulled`, subject a aula, causer o actor, propriedades
só com códigos e contagens: `cause`, `from_lesson_number`,
`attendance_rows_deleted`, `absences_deleted`, `summary_shifted` (bool),
`summary_kept_in_history` (bool), `planning_shifted`, `planning_materialized`,
`planning_pending`, `renumbered_lessons`, `gaps`, `by_direction` (bool). Sem
texto do sumário, sem motivo (o motivo vive no histórico da aula).

Eventos de utilização: `UsageEventType::LessonOutcomeRecorded` (já existe);
não se desconta o `LessonTaught` anterior (os painéis de utilização medem
atividade, não estado).

## 8. Ecrãs

### 8.1 Página da aula (professor que a fechou)

- `outcome_rectification` da página passa a vir também para uma aula
  lecionada **quando** `annulTaught` é permitido: `targets = ['not_held']`,
  `outcome_locked_reason = null`, e uma chave nova `annul: true`.
- Botão «Corrigir: a aula não se realizou» no painel do resultado (onde hoje
  está «Corrigir o resultado» nas aulas não lecionadas). Abre
  `LessonOutcomeDialog` em modo anulação: só a opção «Aula não realizada»,
  causa (lista de sempre, com especificação de greve e descrição de «outra»),
  motivo obrigatório, e o aviso: «Esta aula deixa de contar como lecionada e
  perde o n.º 34. As faltas registadas nesta aula são apagadas. [O sumário passa
  para a aula seguinte. | Há aulas fechadas depois desta: o sumário é removido
  (fica no histórico desta aula) e a numeração fica com uma lacuna; nenhuma
  aula lecionada muda de número.]» — a variante vem do servidor
  (`annul_summary_destination: 'next' | 'history'`).
- Envio: `PATCH /lessons/{lesson}/outcome/annul` (`LessonController::annulTaught`,
  `AnnulTaughtLessonRequest`: `cause`, `cause_note`, `situation`, `motive`).
- Toast: «Marcação de lecionada anulada.» + o destino real do sumário
  (`ShiftLessonPlanning::destinationSentence` ou «O sumário ficou no histórico
  desta aula.»).

### 8.2 Área «Retificações» (direção)

- Em `classifications/rectifications/Index`, cada turma ganha a ligação
  «Aulas fechadas» → `GET classes/{class}/retificacoes/aulas`
  (`LessonAnnulmentController::index`, `Gate::authorize('reviewRectifications', $class)`).
- A página `classifications/rectifications/Lessons` lista as aulas fechadas da
  turma, da mais recente para a mais antiga (até 200; filtro por mês no
  servidor), com: data e hora, turma ou grupo, resultado (etiqueta), n.º da
  lição, nome de quem fechou. **Sem** sumário, faltas, causa detalhada nem
  qualquer dado reservado. A tabela de histórico de anulações da turma
  aparece por baixo (as linhas `kind = annulment`, sem texto de sumário).
- Na linha de uma aula com resultado «Lecionada»: botão «Anular lecionada»,
  que abre o mesmo diálogo (causa, motivo, aviso). Envio para a mesma rota
  `PATCH /lessons/{lesson}/outcome/annul`; a policy decide. A resposta
  redireciona para a lista.
- A direção não ganha acesso à página da aula: `LessonPolicy::view` não muda.

### 8.3 Vista semanal

Sem ação nova. A aula anulada passa a mostrar «Aula não realizada» como
qualquer outra.

## 9. Contagens e relatórios

Não mudam de código: o serviço letivo (`TeacherLessonServiceReport`), o caderno
da turma e a assiduidade dos alunos leem o estado atual da aula e das linhas de
assiduidade. A aula anulada passa a contar como não realizada e as faltas
apagadas deixam de contar. Um teste prova-o no serviço letivo.

## 10. Testes

- **Ação** (`tests/Feature/Lessons/AnnulTaughtLessonTest.php`): sem aulas
  fechadas a seguir — sumário desce, abertas renumeram sem lacuna, assiduidade
  apagada, estado e resultado; com aulas fechadas a seguir — sumário no
  histórico, lacuna no número, lecionadas intactas; número manual passa à
  frente; recusas (não lecionada, lição partilhada, causa `other` sem nota,
  funcionalidade desligada); histórico `kind = annulment` com `lesson_number` e
  `summary_*` só no cenário com fechadas; auditoria sem texto; serviço letivo
  passa a contar «não realizada».
- **Policy e rotas** (`AnnulTaughtLessonAccessTest`): o professor que fechou
  pode; outro professor da turma não; observador não; dono de organização
  institucional pode mesmo sem lecionar; dono de organização pessoal não;
  convidado → login.
- **Página da aula** (`LessonNotHeldUiPropsTest` +): `outcome_rectification.annul`
  e `annul_summary_destination` só para quem pode.
- **Direção** (`LessonAnnulmentControllerTest`): lista sem campos reservados;
  403 para quem não é direção; a anulação a partir da lista.
- **Vue:** `LessonOutcomeDialog` em modo anulação (aviso das duas variantes,
  envio para a rota nova), botão na página da aula, página
  `rectifications/Lessons` (lista, botão só em «Lecionada», diálogo).
- Fixtures de caracterização da Pauta não são tocadas (nada muda na Pauta); se
  alguma prop da página da aula entrar numa fixture, regenerar e dizê-lo.

## 11. Entrega

Uma PR, versão 0.194.0, só código, zero migrations. Deploy pelo procedimento
habitual com autorização expressa. Resposta de suporte ao professor depois do
deploy: abrir a aula de 11 de novembro, «Corrigir: a aula não se realizou»,
causa «Encerramento por mau tempo», motivo; a Lição 34 fica livre e as
seguintes não mudam.
