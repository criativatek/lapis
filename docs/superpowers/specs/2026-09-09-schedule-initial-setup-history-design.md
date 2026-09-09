# Horário inicial com grupos sem vigência artificial

## Contexto

Ao editar um slot recorrente de uma turma desdobrada no próprio dia, a
materialização automática de uma `Lesson` vazia faz atualmente o sistema exigir
`effective_from` e criar uma nova versão temporal. Isso transforma configuração
inicial em histórico, embora ainda não exista qualquer registo pedagógico a
preservar.

## Auditoria do comportamento atual

- `RecurringLessonSlot::requiresVersioning()` considera suficiente que o slot
  esteja vigente e que exista qualquer `Lesson` ligada.
- `starts_on` nulo conta como vigente desde o início; um `starts_on` futuro não
  exige versionamento.
- O único caso atual sem versionamento para um slot iniciado hoje é não existir
  ainda nenhuma `Lesson`.
- `MaterializeLessonsForRange` cria a `Lesson` com estado `preparation` e copia
  `class_group_id` apenas na criação. `firstOrCreate` não atualiza uma aula já
  materializada.
- As relações pedagógicas diretas existentes são `LessonSummary` e `LessonPlan`.
  `EvidenceRecord` relaciona-se com a turma, não diretamente com a `Lesson`, e
  não será usado como motivo para versionar.
- O versionamento histórico é feito por `ReviseRecurringLessonSlot`: fecha a
  linha antiga, cria uma nova e nunca reescreve as `Lessons` antigas.

## Regra canónica

Uma `Lesson` tem histórico pedagógico relevante quando:

1. tem estado `taught`; ou
2. tem `LessonSummary`; ou
3. tem `LessonPlan`.

Uma `Lesson` em `preparation`, sem sumário nem plano, é uma materialização vazia.
Mesmo que a sua data já tenha passado, pode ser alinhada durante a configuração
inicial porque não representa conteúdo pedagógico registado no domínio atual.

Um slot exige versionamento apenas quando está vigente **e** alguma das suas
Lessons tem histórico pedagógico relevante. A existência de uma Lesson vazia,
isoladamente, nunca força vigência artificial.

## Fluxo de edição

### Edição direta

Quando `requiresVersioning()` for falso:

1. validar o pedido normalmente, sem exigir `effective_from`;
2. atualizar o slot na mesma transação;
3. atualizar `class_group_id` das Lessons vazias ligadas ao slot;
4. não criar nova linha de slot, nem preencher `starts_on` por causa da edição;
5. não modificar Lessons com histórico.

Este fluxo suporta, entre outros, turma inteira → T1 e T1 → T2 com uma Lesson
automática vazia do próprio dia.

### Versionamento

Quando houver histórico relevante:

- `effective_from` continua obrigatório;
- a versão antiga mantém as suas Lessons e respetivos snapshots;
- `ReviseRecurringLessonSlot` cria a nova versão com a data escolhida;
- a proteção atual contra alterações retroativas permanece intacta.

## API e UI

O payload dos slots passa a distinguir:

- `already_in_vigor`: indicador temporal usado para remoção e apresentação;
- `requires_versioning`: indicador da proteção histórica necessária para editar.

O editor Vue renderiza e envia `effective_from` apenas quando
`requires_versioning` é verdadeiro. Em edição direta, mostra apenas o botão
normal de guardar, sem mensagem de vigência futura. A listagem continua a
mostrar uma linha de vigência apenas quando o slot tem datas de vigência reais.
Os rótulos de grupos continuam a vir do servidor; não haverá hardcode de T1/T2.

## Testes

Adicionar regressões para:

- turma inteira → T1 com Lesson vazia de hoje: edição direta, sem nova versão,
  `starts_on` inalterado e Lesson atualizada;
- T1 → T2 nas mesmas condições;
- slot futuro sem Lesson: edição direta;
- Lesson com Summary, LessonPlan ou estado `taught`: versionamento exigido;
- Lessons passadas vazias: alinhamento explícito conforme a regra canónica;
- histórico passado com conteúdo: snapshot e `class_group_id` preservados;
- turma sem grupos: comportamento existente;
- ausência de alterações em Assessment/Reporting;
- labels fornecidos pelo servidor.

## Escopo e gates

Não haverá migration. A alteração fica limitada ao domínio/controller/request,
payload/UI do editor e testes de Lessons. Não serão tocados os fluxos de
reimportação de roster/fotos, Enrollment, Assessment, IA, PlanVersions, planos,
landing, upload/ativação de horário ou dados da turma.

Antes de fechar: Pint, Larastan/PHPStan, PHPUnit, ESLint, vue-tsc, Vitest e
build. A QA manual reproduzirá o horário de segunda/quarta/sexta com turma
inteira, T1 e T2 no próprio dia e repetirá a alteração depois de criar histórico
real numa Lesson.
