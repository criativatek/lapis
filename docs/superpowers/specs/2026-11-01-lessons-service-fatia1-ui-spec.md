# Fatia 1 — Especificação técnica da interface («Aula não realizada»)

**Estado:** especificação. Nada disto está implementado nem deve ser ligado antes da
validação jurídica (ver §6). O domínio (PR-1) já existe e o pedido HTTP só aceita
`not_held` com `LAPIS_LESSON_NOT_HELD_ENABLED=true`.

## 1. Princípios

1. **Nada de inferir.** A interface nunca propõe «greve» nem converte registos
   anteriores. A causa e a situação são escolhidas pelo professor.
2. **A causa é do titular.** Só quem registou o resultado a lê
   (`LessonOutcomeDetails`). Um colega, observador incluído, vê «Aula não
   realizada» e «O motivo está reservado a quem registou este resultado».
3. **O servidor decide.** Os botões não prometem o que o servidor recusa
   (`can_record_outcome` já cobre as três recusas de `RecordLessonOutcome::guard()`).
4. **Registo informal («tu») para o professor**, formal para o responsável.

## 2. Pontos de entrada

| Ecrã | Alteração |
|---|---|
| `LessonOutcomeDialog.vue` | Terceira opção «Aula não realizada» ao lado de «Professor ausente» e «Turma em outras atividades letivas». |
| `LessonQuickActions.vue` | Item «Aula não realizada…» nos dois menus (cartão e lote rápido não: o lote só fecha como lecionada). |
| `LessonOutcomePanel.vue` | Já mostra causa/situação ao titular e «reservado» aos outros (props `outcome_cause_label`, `outcome_teacher_situation_label`, `outcome_cause_note`, `outcome_reason_reserved`). |
| Filtro da semana (`lessonWeekView.ts`) | Acrescentar `not_held` a `FILTERABLE_STATES` («Não realizada»). Hoje fica fora porque ainda não há UI que a crie. |

As props da semana (`absenceReasons`) mantêm-se; as causas viajam **uma vez** com a
página, como `absenceReasons`, em `notHeldCauses: {value,label}[]`, só quando a
chave está ligada.

## 3. Diálogo

```
Aula não realizada
 ( ) Greve
 ( ) Escola sem condições de funcionamento
 ( ) Encerramento por mau tempo
 ( ) Problema nas instalações
 ( ) Razão organizacional da escola
 ( ) Outra causa  → [descrição curta, obrigatória, até 160]

 Se «Greve»:
   A tua situação nesse dia
   ( ) Não aderi, mas a escola não funcionou        → not_adhered
   ( ) Prefiro não indicar / não confirmado         → not_informed  (por omissão)
```

- A situação só aparece com «Greve» e nunca vem pré-seleccionada em `not_adhered`.
- Um observador **não vê** a pergunta da situação (o servidor força `not_informed`).
- A linha «Aderi» **não existe** aqui: é o cenário A (`teacher_absent` + `strike`),
  que depende da chave `lapis.strike_adhesion_recording` e de uma alteração do
  enum `TeacherAbsenceReason` + do `LessonOutcomeLegalTest` (§6).
- Texto de apoio: «Esta aula não conta como lecionada nem leva número. O
  planeamento passa para a aula seguinte.» Reutiliza `ShiftLessonPlanning::destinationSentence`.
- Descrição de «Outra causa»: campo próprio (`cause_note`), com o aviso «Evita incluir
  dados pessoais» e **sem ditado** nesta fase.

Campos enviados: `outcome=not_held`, `cause`, `situation?`, `cause_note?`.

## 4. Cartão e página da aula

- Cartão da semana: etiqueta «Aula não realizada» (tom âmbar, ícone `UserX` até haver
  um próprio) e «Não conta na numeração», como «Professor ausente».
- Página da aula: o bloco de resultado mostra, **só ao titular**, «Causa: …» e, se
  existir, «A tua situação: …» e a descrição. Aos outros, a linha de reserva.
- Nunca em listas, pesquisa, painel, exportações de relatório nem prompts de IA.

## 5. Critérios de aceitação da interface

1. Com a chave desligada, a opção não existe no DOM e o servidor recusa o pedido.
2. Colega e observador não recebem causa, situação nem descrição em nenhum JSON.
3. «Outra causa» sem descrição não envia e mostra o erro do servidor.
4. A situação nunca é enviada sem «Greve».
5. Depois de registar, o toast diz o destino real do planeamento.
6. Teclado e leitor de ecrã: grupo de rádio com legenda, foco devolvido ao botão de origem.
7. Vitest: diálogo (campos condicionais), painel (titular vs. reservado), filtro.

## 6. Bloqueios fora do código

1. **Política de Privacidade e Acordo** (`LegalDocuments`): descrever a causa, a situação
   individual perante a greve e a descrição; nova data de entrada em vigor.
   `LessonOutcomeLegalTest` fixa os motivos e os campos do pedido: **muda com o texto legal**,
   nunca antes.
2. **Validação jurídica** da categoria «situação perante a greve».
3. **Cenário A** (`teacher_absent` + `strike`): acrescenta o caso `Strike` a
   `TeacherAbsenceReason` (o teste legal fixa `['training','official_duty','other']`),
   filtra-o por `lapis.strike_adhesion_recording` em `LessonController`,
   `LessonWeekController` e na regra do pedido, e só avança com (1) e (2) resolvidos.
4. **Backup/exportação** continuam a levar a causa dos colegas (o validador a exige).
   Restringir a exportação aos dados do próprio é um seguimento ligado à Política.

## 7. Registo retroativo (PR-3, backend feito; interface por fazer)

O pedido aceita `retroactive=true` só com `outcome=not_held` e a funcionalidade ligada. A
interface deve:

1. Oferecer «Registar mesmo havendo aulas posteriores fechadas» **só** quando
   `can_record_outcome` for falso por causa de uma aula fechada posterior **e** a aula
   estiver vazia. O servidor continua a decidir: recusa com a razão («tem sumário…»,
   «tem faltas em rascunho», «número indicado por si», «partilha a lição com outro
   grupo», «planeamento pendente») e remete para o tratamento individual.
2. Dizer antes de confirmar: «Nenhuma aula já lecionada muda de número. As aulas abertas
   antes de uma lecionada contam para trás a partir dela, por isso pode ficar uma
   lacuna na numeração.»
3. Mostrar, depois, as lacunas (`gaps` da auditoria) no toast.

Regra de numeração (não da operação): as abertas anteriores à primeira lecionada
numeram-se para trás a partir dela; a lacuna fica no início da sequência.

