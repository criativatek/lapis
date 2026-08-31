# ADR-0012 — Uma data importada do calendário escolar é um acontecimento até se provar que é um feriado

- **Status:** Accepted — implementado em `feat/calendar-dated-events`, com a
  release **0.102.0** preparada nessa branch. Ainda **não** integrado em `main`
  e **sem deploy**.
- **Date:** 2026-08-31
- **Não altera:** o modelo da Fase 5.4. `academic_calendar_exceptions` e
  `calendar_events` continuam a ser duas tabelas, com os mesmos enums e a mesma
  linha entre elas. **Nenhuma migração**: o que estava errado era a leitura, não
  o esquema.
- **Não altera:** [ADR-0002](0002-container-resolved-tenancy.md). Nada aqui lê
  ou escreve fora do inquilino resolvido.

## Context

A importação do calendário escolar lia a grelha dos meses e, no fim de
`AcademicCalendarParser::readDayGrid()`, escrevia **tudo** o que sobrasse dos
filtros como `AcademicCalendarExceptionType::Holiday`. Não havia classificação
nenhuma a correr: «feriado» era o valor por omissão de uma pergunta que nunca
chegava a ser feita. O cabeçalho da classe dizia-o com todas as letras — «sem cor
nenhuma, um dia com nome é um feriado, que é o caso comum e o palpite seguro».

Não era seguro. Um calendário escolar publicado por um agrupamento marca, na
mesma grelha e do mesmo modo, coisas que não são feriados nenhuns:

- «Apresentação dos alunos»
- «Reunião de avaliação»
- «Almoço-convívio»
- «Visita de estudo»
- entregas, provas, comemorações, dias não letivos concedidos

E nesta aplicação «feriado» **não é um rótulo**. Uma `AcademicCalendarException`
é a única coisa deste calendário que afirma que naquele dia **não há aula** — é
estrutura da organização inteira, ao lado do `AcademicPeriod`, e é o que a Fase
5.5 lê para decidir que aulas materializar. Escrever uma reunião como feriado não
punha uma palavra errada num ecrã: **apagava as aulas daquele dia**, para toda a
escola, a partir de uma célula de Excel que dizia «Reunião».

A pré-visualização, por cima, agrupava a lista inteira debaixo do título
**«Feriados»**, e o professor confirmava-a sem ter como descobrir o que a
aplicação estava a afirmar em seu nome.

## Decision

**Uma data com nome lida da grelha é um acontecimento de calendário, e só é um
dia sem aula quando alguma prova o disser.** A classificação vive numa classe
sua, `ClassifyCalendarDay`, e decide por ordem de força da prova:

1. **O que o documento escreve.** «Feriado municipal» diz-se feriado a si
   próprio; «Dia não letivo» diz-se dia não letivo; «Reunião», «visita de
   estudo», «convívio» e «atividade» (que não seja «atividade letiva») dizem-se
   o que são. É classificação explícita do ficheiro e ganha a tudo o resto.
2. **O que a lei diz daquela data.** 25 de dezembro é feriado nacional em
   Portugal por lei, e não por palpite: responde o mesmo `NationalHolidayProvider`
   que a sugestão de feriados já usa, só para o `country_code` do ano letivo, e
   `null` — nunca Portugal por omissão — para qualquer outro país.
3. **O que o documento pinta.** A folha de referência realça os seus feriados com
   uma cor forte, e essa cor é a classificação que a escola lhes deu. É por esta
   prova que o «Dia de Leiria» — feriado **municipal**, que provider nacional
   nenhum pode conhecer — continua a ser lido como feriado.
4. **Nada.** E «nada» responde-se com o tipo **neutro** (`CalendarEventType::Other`,
   que o professor lê como «Data relevante»), nunca com feriado.

A regra da cor **estreitou**: continua a valer como prova, mas a ausência de cor
deixou de valer como prova do contrário. Uma célula sem realce é uma célula sobre
a qual o documento não se pronunciou.

### O que não se adivinha

As expressões reconhecidas em (1) são poucas de propósito e todas de ambiguidade
baixa. **«Apresentação» não está na lista e não vai estar** — tanto é o primeiro
dia de aulas como um sarau —, e uma palavra frágil classificada à sorte é
exatamente o que produziu este problema. O que não se sabe fica «Data relevante»,
à vista, explicado e por confirmar; o professor decide (§1, §3.3).

### O ecrã

A pré-visualização deixou de ter duas listas («Feriados» e «Outros
acontecimentos») e passou a ter **uma**: **«Datas e eventos escolares»**,
ordenada **por data**, que é a ordem por que um calendário se lê. Cada linha diz
o que é — «Feriado», «Dia não letivo», «Reunião», «Atividade», «Visita de
estudo», «Data relevante» — e para onde vai; `destination` viaja em cada linha e
separa, no momento de gravar, o que é estrutura do ano do que é calendário do
professor.

## Consequences

- **Um dia sem aula deixa de nascer de um palpite.** É o ponto todo.
- **A troco disso, um feriado que o documento não realce, não nomeie e não seja
  nacional passa a entrar como «Data relevante».** É o erro do lado barato: uma
  data mal tipada corrige-se no calendário em segundos, uma aula apagada por uma
  reunião não se descobre até faltar.
- **`events.*.type` aceita as quatro espécies** do `CalendarEventType` em vez de
  só `other`: recusar aqui uma «Reunião» que a própria pré-visualização propôs
  como reunião seria recusar o ecrã anterior.
- **Nenhuma reclassificação de histórico.** As linhas que importações anteriores
  escreveram como feriado ficam como estão. Distingui-las hoje exigiria reler
  ficheiros que já não existem, e apagar por regra o que um professor possa ter
  confirmado ou corrigido à mão seria trocar um erro por outro. Corrige-se o
  comportamento futuro; se um dia houver uma regra segura para o passado, é outra
  fatia e outra decisão.
- **A pré-visualização não deixa mudar o tipo de uma linha.** Quem discordar da
  classificação desmarca-a e escreve-a à mão, como sempre pôde. Um seletor por
  linha é uma melhoria óbvia e fica registada aqui como tal — não foi feita nesta
  fatia para não misturar «classificar bem» com «editar durante a importação».
