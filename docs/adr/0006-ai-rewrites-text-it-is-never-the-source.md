# ADR-0006 — A IA reescreve texto; nunca é a fonte

- **Status:** Accepted
- **Date:** 2026-08-19

## Context

O módulo Relatórios produz frases determinísticas a partir de dados verificados
([ADR-0005](0005-reports-are-documents-not-downloads.md)). São corretas e, por
construção, algo mecânicas: os compositores montam orações a partir de factos e
não têm ouvido. Um professor que queira entregar o documento a uma direção ou a
um encarregado de educação acaba a reescrever parágrafos à mão.

A tentação óbvia — e o que quase todo o produto do setor faz — é ligar um modelo
de linguagem aos dados e pedir-lhe o relatório. **Isso está fora de questão
aqui**, e não por conservadorismo:

1. **Um relatório pode mentir de forma credível.** É o modo de falha central do
   módulo, já identificado no ADR-0005. Um modelo de linguagem é a ferramenta
   mais eficaz alguma vez construída para o produzir: escreve «a turma revela
   falta de hábitos de estudo» com a mesma fluência com que escreve uma frase
   verdadeira, e ninguém que leia o documento consegue distinguir as duas.
2. **Os dados são sobre menores.** A maioria dos alunos do LÁPIS são crianças.
   «Enviar dados identificativos de alunos para um serviço externo» está na lista
   de coisas que não se fazem sem perguntar (CLAUDE.md §31).
3. **Uma classificação é uma decisão de uma pessoa.** O sistema propõe; o
   professor confirma e publica (§3.3, §7). Um texto que transforme uma proposta
   numa classificação atribuída relata uma decisão que ninguém tomou.

## Decision

### 1. A seta tem um sentido só

```
dados canónicos → narrativa determinística → o professor edita
                → a IA aperfeiçoa UMA secção → o professor decide → finaliza
```

e nunca `dados → IA → relatório`. Nada em `App\Services\Reporting\Writing` lê
uma estatística, um resultado, uma classificação ou um registo de aluno. O
serviço lê **o texto de uma secção** — que os compositores já escreveram, ou que
o professor já escreveu — e pede a mesma coisa dita melhor. O motor não pode ser
fonte de um facto porque nunca lhe é mostrado nenhum.

O botão chama-se **«Aperfeiçoar redação»**. Não «Gerar relatório com IA».

### 2. O LÁPIS não escolhe fornecedor

`config/lapis.php` traz a tomada, não a ficha: sem driver por omissão, sem
endpoint, sem modelo, sem chave. `AiTextProviders::isConfigured()` devolve
`false` numa instalação nova, e **o módulo Relatórios funciona inteiramente nesse
estado**. O driver `chat-completions` nomeia um formato de fio — o `POST` que
quase todos os motores alojados e todos os motores locais implementam — e quem
define `LAPIS_AI_ENDPOINT` decide para onde vai o texto, incluindo para uma
máquina dentro da escola.

A capability é `ai_assistance`, que já existia no `EntitlementsSeeder` desde que
ele foi escrito: Pro e Institucional, nunca Base. Não foi criada nenhuma chave
nova, porque isso implicaria mexer na composição comercial dos planos.

### 3. Um número errado não é improvável — é inexprimível

Antes de sair, `ProtectedFacts` substitui **todas** as percentagens, datas,
decimais, referências a nível, quantidades por extenso a partir de dois, e
ordinais de período por marcadores **com letras**: `[[FA]]`, `[[FB]]`. O texto
que sai não contém um único algarismo.

Daí decorre a regra mais forte do guarda: **qualquer algarismo na resposta foi
escrito pelo modelo**. Não é preciso analisar nada.

«Um» e «uma» são a exceção deliberada — são também o artigo indefinido, e um
texto onde cada «uma» é um marcador é um texto que nenhum modelo reescreve bem.
O buraco fecha-se do outro lado: o guarda recusa uma resposta que aumente o
número de expressões com a forma «um aluno».

### 4. Nomes saem como «Aluno A»

`PseudonymMap` constrói-se a partir da pauta da turma e do aluno do relatório
individual, substitui os nomes conhecidos antes do envio e repõe-nos na sugestão.
O que um sistema remoto guarda nos seus registos é uma letra.

**O que isto não consegue fazer, escrito e não desejado:** só conhece os nomes
que lhe foram dados. Um professor que escreva num campo livre o nome de um irmão,
de um colega ou de um aluno de outra turma escreveu um nome que este mapa nunca
viu. Por isso o menu das secções que podem conter nomes diz em voz alta o que vai
acontecer — para que enviá-los seja uma decisão de alguém e não do software.

### 5. O guarda só pode errar para o lado seguro

`RewriteGuard` não é um analisador semântico e não tenta ser. Cada verificação é
uma comparação mecânica entre o que foi enviado e o que voltou, e **cada uma só
pode produzir uma recusa falsa**:

| Verificação | Contra o quê |
|---|---|
| marcadores | um valor alterado, perdido ou inventado |
| algarismos | um número escrito pelo modelo |
| quantidades | um «um aluno» que apareceu do nada |
| léxico de invenção | dificuldades, causas, diagnósticos, estratégias, medidas, legislação, caracterizações de comportamento, adjetivos de juízo, e palavras que transformam uma proposta numa decisão |
| léxico de preservação | uma autoavaliação, uma proposta, uma ausência de dados ou uma reserva que desapareceu |
| dimensão | uma resposta metade mais longa do que a pergunta |
| formato | HTML, Markdown, blocos de código |

Uma sugestão recusada custa um clique a um professor. Uma frase aceite por engano
entra num documento sobre uma criança. A escolha entre as duas não é difícil.

### 6. `generated_body` nunca muda

A resposta aceite escreve `body` e marca `edited`. `generated_body` continua a
ser o texto determinístico, e por isso **«restaurar texto automático» continua a
restaurar o que o LÁPIS escreveu** — não o que um modelo disse. «Regenerar»
continua a significar reexecutar os compositores, e nunca chama IA.

### 7. O rasto não guarda texto

Reutiliza `audit_events`. Uma linha diz que uma reformulação foi pedida para esta
secção, neste modo, que motor respondeu, o que o guarda decidiu e quanto custou —
com **hashes** do que entrou e do que saiu, e nenhuma palavra do relatório. Um
registo de auditoria cheio de dificuldades de crianças seria um problema de
proteção de dados por direito próprio.

## Consequences

- A funcionalidade está **desligada** em qualquer instalação que não a configure
  explicitamente, incluindo a de produção. Isto é o estado pretendido: escolher o
  fornecedor é uma decisão a tomar com o utilizador, não uma a tomar por ele.
- O guarda vai recusar reformulações legítimas. É aceitável e é o desenho.
- O prompt é **fechado e versionado** (`WritingPrompt::VERSION`). Não há campo de
  texto livre em lado nenhum: nem instruções personalizadas num modelo de
  relatório, nem prompt por organização. Cada um desses seria um sítio onde
  alguém escreveria «e sugere o que o aluno deve fazer a seguir».
- Qualquer alteração ao texto do prompt exige uma versão nova, para que uma linha
  de auditoria antiga continue interpretável.

## Dívidas registadas

1. **Não existe página de proteção de dados.** O módulo `data_protection` está no
   catálogo de capabilities e não tem ecrã. Quando existir, o que este ADR
   descreve na secção 4 deve aparecer lá, em português e para o professor — hoje
   vive no código e no menu da funcionalidade.
2. **Sem consentimento por organização.** Configurar o motor é uma decisão do
   operador da instalação; uma escola dentro dessa instalação não tem forma de
   recusar. Enquanto o LÁPIS for maioritariamente monoinstitucional isto é
   equivalente, mas deixa de o ser no dia em que não for.
3. **Sem medição de custos.** Os contadores de tokens são gravados desde o
   primeiro dia (`AiTextResponse::metrics()`), mas não há painel que os leia.
   Deliberado: a arquitetura não está fechada, e um painel sem utilizadores é
   código morto.
4. **Pedido síncrono.** Sem fila. O timeout é curto e o texto é preservado em
   qualquer falha; introduzir uma fila só para isto não se justificava.
