# Questões agrupadas por domínio no formulário de instrumento — desenho

## Contexto

Hoje, ao criar/editar um instrumento, a secção "Questões" é uma lista plana:
cada questão tem o seu próprio pequeno editor de distribuição por domínios
(`+ Domínio`, escolher da lista completa, indicar %). O botão "+ Adicionar
questão" está fora e antes de qualquer contexto de domínio — uma questão
nasce sem nenhum domínio associado, e só depois é que se lhe atribuem
domínios, um a um.

Isto inverte a forma como um professor pensa no instrumento: primeiro sabe
que domínios está a avaliar (ex.: "este teste cobre Oralidade, Leitura e
Escrita"), só depois é que escreve as perguntas que avaliam cada um. Este
documento reorganiza o formulário para seguir essa ordem.

## Regras decididas

1. **Nova secção "Domínios avaliados", antes de "Questões"**: uma lista de
   checkboxes com os domínios da disciplina da turma (os mesmos já enviados
   em `domains`, hoje só usados dentro do editor por questão). O professor
   marca os que este instrumento cobre.
2. **Uma secção por domínio marcado**, cada uma com o seu próprio botão
   "+ Adicionar questão", **scoped a esse domínio**: a questão nasce já com
   100% alocado a esse domínio, sem precisar de abrir nenhum editor de
   alocação à parte.
3. **Uma questão continua a poder contar para mais do que um domínio** — o
   mecanismo já existente (o pequeno editor de alocação dentro de cada
   questão, com o seu próprio "+ Domínio") mantém-se tal e qual, agora
   mostrado dentro da secção do domínio onde a questão já vive. Adicionar aí
   uma segunda alocação para outro domínio faz essa questão aparecer também
   na secção desse outro domínio (a fonte de dados é a mesma — a
   apresentação por domínio é só uma vista agrupada sobre a mesma lista de
   questões).
4. **Desmarcar um domínio não apaga nem bloqueia alocações já feitas para
   ele** — só deixa de mostrar a secção (com o botão de adicionar questão
   scoped a esse domínio). Uma questão que já tinha uma alocação a esse
   domínio mantém-na (visível só através da secção do OUTRO domínio a que
   também pertence, se tiver; se não tiver mais nenhum, a questão continua a
   existir mas sem nenhuma secção de domínio a mostrá-la — ver regra 5).
5. **Questões sem domínio continuam legítimas** — uma secção final,
   "Sem domínio associado" (sempre visível, independente de checkboxes),
   com o seu próprio "+ Adicionar questão", cobre este caso já suportado
   hoje ("uma questão de apresentação pode não pertencer a nenhum domínio").
6. **Sem mudança nenhuma ao modelo de dados nem ao backend.** `instrument_items`/
   `item_domain_allocations` já suportam isto integralmente — isto é
   puramente uma reorganização do formulário sobre os mesmos dados
   (`form.items`, cada um com a sua lista de `domains`). `InstrumentBuilder`,
   `InstrumentRequest` e as rotas ficam inalterados.

## Dependência de sequência

Tal como a importação de estrutura de outro instrumento
(`docs/superpowers/plans/2026-07-30-instrument-import-template.md`), esta
funcionalidade mexe no mesmo formulário que o plano de edição de
instrumentos em curso está a extrair para `InstrumentForm.vue`. **Só avança
depois de `docs/superpowers/plans/2026-07-29-instrument-editing.md` estar
completo e mesclado** — implementar antes duplicaria trabalho e arriscaria
conflitos.

## Frontend

Puramente uma reorganização de `resources/js/pages/instruments/Create.vue`
(ou `InstrumentForm.vue`, o que existir nessa altura — reconfirmar antes de
começar, exatamente como já indicado no plano de importação de estrutura).

- Novo estado reativo `selectedDomainIds: Set<number>` (ou array), inicialmente
  vazio.
- Nova secção "Domínios avaliados": um checkbox por domínio em `props.domains`,
  ligado a `selectedDomainIds`.
- A secção "Questões" atual é substituída por uma iteração sobre
  `selectedDomainIds` (mais a secção fixa "Sem domínio associado" no fim):
  para cada domínio selecionado, uma função `itemsForDomain(domainId)`
  filtra `form.items` pelos que têm alguma alocação a esse `domain_id` —
  puramente uma vista computada, `form.items` continua a ser a única fonte
  de verdade.
- "+ Adicionar questão" dentro da secção do domínio D cria uma nova entrada
  em `form.items` com `domains: [{ domain_id: D, allocation_percent: 100 }]`
  já preenchido, em vez de `domains: []` como acontece hoje.
- "+ Adicionar questão" na secção "Sem domínio associado" continua a criar
  `domains: []`, exatamente como hoje.
- O editor de alocação por questão (código já existente: `+ Domínio`,
  selecionar outro domínio, indicar %, remover) mantém-se sem alteração
  nenhuma na sua lógica — só a sua posição na página muda (agora dentro da
  secção do domínio "principal" da questão, não numa lista plana única).
- Remover uma questão continua a operar sobre o índice real em `form.items`
  (não muda), só a partir de qualquer secção onde a questão apareça.
- A validação/soma de cotações já existente ("Soma das cotações") mantém-se
  inalterada, continua a somar `form.items` na íntegra, independentemente de
  quantas secções de domínio existam.

## Testes

Sem ambiente de testes automatizado para o frontend (já confirmado nesta
sessão). Verificação manual: marcar 2-3 domínios mostra as suas secções;
adicionar uma questão dentro de uma secção já nasce 100% alocada a esse
domínio; usar o editor de alocação existente para adicionar um segundo
domínio a essa questão faz-a aparecer também na secção desse segundo
domínio; desmarcar um domínio esconde a sua secção sem apagar alocações já
feitas; uma questão sem nenhum domínio aparece na secção "Sem domínio
associado"; a soma das cotações continua correta independentemente do
agrupamento; submeter o formulário cria o instrumento exatamente como hoje
(o payload enviado ao backend não muda de forma nenhuma).
