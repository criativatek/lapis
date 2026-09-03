# ADR-0013 — Um reporte pode sair para um rastreador externo, por acto de uma pessoa

- **Status:** Accepted — implementado na 0.111.0, **desligado por configuração**.
  Ligar exige o trabalho legal da secção «Antes de ligar» — estado por item na própria secção (actualizada a 2026-09-03).
- **Date:** 2026-09-02
- **Contradiz:** [ADR-0011](0011-support-centre.md) §4 — «a conversa canónica é a
  do Lapispro» — e a §31 do `CLAUDE.md`, que põe enviar dados identificáveis de
  alunos para um serviço externo na lista do que não se faz sem perguntar.
- **Decisão de:** Pedro Alves, reafirmada depois de a objecção ter sido
  levantada duas vezes com os factos à frente.

## Context

Um reporte feito pelo widget traz contexto técnico e, quando o professor a
certifica, uma captura do ecrã dele. Trabalhar esse reporte acontece noutro
sítio — onde o código vive — e copiar à mão o que é preciso para lá é trabalho
repetido que ninguém faz duas semanas seguidas.

O sistema equivalente do Plaanly resolve isto despejando **cada** reporte num
GitHub Issue assim que ele chega, com descrição, logs e imagens. É a forma
óbvia, e é a que aqui não se pode copiar: uma captura de ecrã do Lapispro é, por
construção, uma tabela com nomes de crianças contra as suas classificações.

## Decision

### 1. Exportar é um acto, nunca um automatismo

Um operador lê o pedido, escreve uma nota, e carrega num botão. **A nota é
obrigatória**, e não é burocracia: é a única prosa que atravessa a fronteira, e
tê-la de escrever obriga a ler o pedido antes de o mandar para fora. Um botão que
exportasse sem nota seria o automatismo do Plaanly com um clique pelo meio.

### 2. Lista de permissões, e nunca de exclusões

`IssueGithubPayload::ALLOWED` enumera as chaves que podem sair, e o teste
compara-as por **igualdade** com uma lista literal. Uma lista de exclusões —
«tudo menos o `subject`» — vaza no dia em que alguém acrescenta uma coluna e não
se lembra dela; esta quebra nesse mesmo dia.

Sai: referência, categoria, severidade, código técnico, versão, rota mascarada,
nome do ecrã, browser, plataforma, viewport, a forma dos erros, os pedidos com
estado ≥ 400.

**Não sai, e é o ponto:** o `subject`, a `description`, qualquer mensagem da
conversa, o nome ou o email de quem reportou, as imagens, e **a consola** — que é
a única parte do contexto que leva texto que a aplicação não compôs.

### 3. O título não leva o resumo

`subject` é o primeiro sítio onde alguém escreve «o aluno João não aparece na
turma 5.ºB» sem pensar duas vezes. É o mesmo argumento que a ADR-0011 §14 usou
para o manter fora dos emails, e vale por maioria de razão para um sistema que
não é nosso.

### 4. Síncrono, como o resto deste domínio

Não há worker de filas em produção (ADR-0011 §5). Um job `ShouldQueue` seria
escrito na tabela `jobs` e **nunca corria**, e o botão ficaria a dizer que
exportou.

### 5. O repositório tem de ser privado

Um issue com contexto de um professor num repositório público não é uma
transferência para um subcontratante — é uma divulgação. É a única coisa aqui
que nenhuma configuração posterior desfaz.

### 6. A eliminação aos 24 meses passa a ser «o melhor que se consegue»

E esta é a consequência que obriga a que isto seja uma decisão e não um passo.

A API do GitHub não permite apagar um issue por REST. `redact()` reescreve o
título e o corpo e fecha-o — mas **o GitHub guarda o histórico de edições**,
visível a quem tem acesso ao repositório. Dentro do Lapispro a anonimização
apaga; lá fora, reduz.

A Política de Privacidade promete eliminação. Enquanto esta funcionalidade
estiver ligada, essa promessa tem uma excepção, e uma excepção não declarada é
uma promessa falsa.

### 7. Desligado por configuração, e é essa a condição para existir

Sem `LAPIS_SUPPORT_GITHUB_TOKEN` e `LAPIS_SUPPORT_GITHUB_REPOSITORY`,
`ExportIssueToGithub::isConfigured()` devolve `false`, o botão não aparece e o
método recusa-se a correr. **O código existe apagado**, e é assim que fica até o
trabalho abaixo estar feito.

## Antes de ligar — estado a 2026-09-03

1. ~~**`docs/legitimate-interest-support.md`**~~ — **feito na 0.112.0**: a §2
   ganhou o âmbito explícito (o reporte de dentro da aplicação assenta na
   alínea b) e está fora daquele documento) e as duas linhas da §5 passaram a
   dizer de que canal falam. `SupportPrivacyAlignmentTest` prende-o.
2. **`LegalDocuments::privacy()`** — **GitHub nomeado na 0.115.0**: entrada nos
   Subcontratantes (GitHub, Inc., EUA, subsidiária da Microsoft), linha nas
   Transferências internacionais, e o parágrafo em «Contactos e suporte» com o
   que segue e — mais importante — o que nunca segue (a lista `ALLOWED` dita
   por palavras: sem texto do utilizador, sem identidade, sem imagens).
   **Continua por fazer** a correção do parágrafo da IA — falso desde
   2026-09-01 —, bloqueada em confirmar o tier (Free/Paid) da chave Gemini do
   projecto Google; é trabalho da IA e não desta exportação, mas fica registado
   aqui até estar fechado.
3. ~~**A exceção da §6 escrita na Política**~~ — **feito na 0.115.0**: item
   próprio em «Durante quanto tempo» — reescrito e fechado é o máximo que a
   API permite, e o histórico de edições fica, dito por palavras.
4. **Confirmar que o repositório é privado** — verifica-se pela API no momento
   de configurar o token, antes de o escrever no `.env` de produção.

## Consequences

- Um reporte exportado deixa de estar inteiramente sob o relógio de retenção que
  a Política promete. O `github_issue_number` fica guardado precisamente para
  tornar o expurgo possível, e vai a NULL na anonimização — um apontador para
  conteúdo é conteúdo.
- A conversa canónica continua a ser a do Lapispro: o issue é um espelho parcial
  e composto, não uma cópia.
- `IssueGithubExportTest` é o que mantém tudo isto verdadeiro. Se a lista de
  permissões crescer sem alguém decidir, ele fica vermelho.

## Alternatives considered

- **Exportação automática, como no Plaanly.** Recusada: transforma uma decisão
  caso a caso numa política que ninguém aprovou, e é justamente a decisão que
  esta ADR existe para registar.
- **Enviar o corpo do pedido e as imagens.** Recusada: é o que o Plaanly faz, e é
  o que a §31 proíbe. O que sai é composto, não copiado.
- **Não exportar de todo, e ficar pelo «copiar como texto».** Continua a ser a
  opção com menos consequências, e é a que está em vigor enquanto isto estiver
  desligado — o botão de copiar dá quase todo o valor sem transferir nada.
