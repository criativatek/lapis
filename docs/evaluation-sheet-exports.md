# Pautas de Avaliação — o que sai em ficheiro

Quatro coisas diferentes saem da Pauta de Avaliação, e confundi-las é a
maneira mais fácil de escrever no passado. Este documento diz o que cada uma
é, de onde tira os números, e o que não pode fazer.

## As quatro saídas

| Saída | Rota | Origem dos números | Deixa registo? |
|---|---|---|---|
| **Impressão** | nenhuma (`@media print` no próprio ecrã) | a pauta viva, **como está no ecrã** (WYSIWYG — respeita os toggles) | não |
| **CSV / Excel da pauta atual** | `evaluation-sheets.csv` · `evaluation-sheets.xlsx` | a pauta viva, **sempre completa** (os toggles não chegam ao servidor) | auditoria |
| **CSV / Excel de um momento guardado** | `evaluation-sheets.snapshot.csv` · `.xlsx` | **só o payload congelado** | auditoria |
| **Grelha do Inovar** | `evaluation-sheets.inovar.*` | uma pauta guardada, escrita na grelha da escola | histórico + ficheiro imutável |

A impressão e o ficheiro de dados seguem regras **opostas de propósito**: uma
folha impressa que ignorasse o que o professor escolheu ver seria a ferramenta a
discordar dele; um ficheiro de dados que omitisse colunas em silêncio conforme o
ecrã seria uma armadilha para quem o abre uma semana depois.

## A regra que não pode cair

**O ficheiro de um momento representa esse momento.**

O professor atribui 3 a 10 de novembro e guarda. A 12 muda para 4. A partir daí:

- a **pauta atual** diz 4 — a decisão é sempre reeditável enquanto houver
  autorização, e guardar, exportar ou ter histórico não a fecham;
- o **ficheiro do momento de 10** continua a dizer 3, byte a byte, para sempre;
- quem quiser o 4 em arquivo **guarda um momento novo** e exporta esse.

Isto é fácil de partir por distração: bastaria o exportador chamar
`BuildEvaluationSheet` «para ter os dados frescos». Por isso a separação é
**estrutural** e não um hábito:

- [`EvaluationSheetSnapshotExportController`](../app/Http/Controllers/EvaluationSheetSnapshotExportController.php)
  **não recebe** `BuildEvaluationSheet`, nem o calculador, nem a escala. Recebe
  um escritor e um payload. O presente não está na sala.
- [`EvaluationSheetDocument::fromSnapshot()`](../app/Services/Assessment/Export/EvaluationSheetDocument.php)
  lê o payload e mais nada — nem sequer a paleta de cores de hoje, porque a cor
  que estava no ecrã viajou com a fotografia.
- `EvaluationSheetSnapshotExportTest` exercita a regra **e** verifica-a nas
  assinaturas dos ficheiros.

## Uma forma, duas origens

`EvaluationSheetDocument` é a forma partilhada. As colunas do ficheiro da pauta
atual e as do momento guardado são **as mesmas de propósito**: um professor que
junte os dois num mesmo livro está a comparar dois momentos, e colunas
desalinhadas tornariam isso adivinhação. O que nunca se cruza são as origens —
`fromLiveSheet()` e `fromSnapshot()`.

No ficheiro, a **proposta** e a **decisão** têm colunas separadas. Num CSV não há
negrito nem itálico, e a distinção que o ecrã faz pela tipografia só a estrutura
a pode fazer aqui; «Origem do nível» di-lo por palavras.

Isso vale para o juízo global **e para cada domínio**. Cada domínio sai em três
colunas — `Percentagem`, `Apreciação`, `Proposta do Lapispro` — mais
`Origem da apreciação` no CSV:

| Coluna | O que é |
|---|---|
| `X — Percentagem` | o **quantitativo calculado** pelo motor. Não muda por causa de decisão nenhuma. |
| `X — Apreciação` | o que **vale**: a decisão do professor quando existe, a proposta quando não existe. |
| `X — Proposta do Lapispro` | a banda em que o quantitativo caiu, **sempre preservada**. |
| `X — Origem da apreciação` | «Decisão do professor» ou «Proposta do Lapispro», dito por palavras. |

**Quantitativo calculado ≠ apreciação decidida.** São três afirmações distintas
e simultaneamente verdadeiras: 47,5% é o que o motor apurou, «2» é o que o
Lapispro propõe, «3» é o que o professor decidiu. Nenhuma apaga as outras, e o
professor não altera nenhuma das duas primeiras — só a sua.

Uma pauta guardada antes de esta decisão existir não traz as chaves
`decided_*`, e a ausência delas **é** a informação: ninguém se pronunciou. O
ficheiro histórico continua a dizer exatamente o que dizia.

O que é proposta e o que é decisão — no global e por domínio — está em
[evaluation-sheet-decisions.md](evaluation-sheet-decisions.md).

## A grelha do Inovar: de quem é cada linha

É a pergunta mais perigosa deste fluxo. Uma menção escrita na linha errada sai
da escola como se fosse a nota daquela pessoa, e ninguém a apanha a ler. Por
isso a resposta é **por confiança** e não por uma chave só —
[`InovarStudentMatcher`](../app/Services/Export/InovarStudentMatcher.php).

| Confiança | Quando | O que acontece |
|---|---|---|
| **Forte** | o N.º de processo coincide dos dois lados; ou o nome coincide no primeiro e no último e não há outro candidato | o Lapispro escreve |
| **Provável** | o nome é forte mas o N.º de processo diverge; ou o número aponta uma pessoa e o nome outra | diz quem acha que é, e **espera** |
| **Ambígua** | dois candidatos plausíveis | não sugere nenhum: o professor escolhe |
| **Sem correspondência** | nada bate certo | a linha fica **exatamente como estava** — nunca um zero, nunca um F |

**O N.º de processo deixou de ser requisito.** É um sinal forte quando existe
dos dois lados; quando o Lapispro não o tem, não penaliza — o nome responde à
mesma pergunta. Uma turma escrita à mão exporta.

**Nome forte é primeiro e último**, depois de normalizar acentos, maiúsculas,
hífenes, apóstrofos e partículas («de», «do», «da»…). Nomes do meio podem
existir só de um lado ou estar abreviados: «Álvaro Manuel Simões» ↔
«Alvaro Simões». O que **não** existe é aproximação — «Martins» e «Martin» não
correspondem, nem sequer como sugestão automática.

**A mesma matrícula nunca pode ser reclamada por duas linhas.** Irmãos com o
mesmo primeiro e último nome, uma linha duplicada: ambas passam a ambíguas, em
vez de uma delas levar a nota errada.

**A resposta do professor não é uma instrução.** Cada escolha é reavaliada
contra os candidatos que aquela linha admite; uma escolha fora dessa lista é
descartada e a linha volta a pedir resposta. O ficheiro é sempre relido do
disco.

**O que bloqueia** são três coisas, e nenhuma delas é «falta informação»: uma
coluna que não corresponde a domínio nenhum, uma escala sem correspondência
INOVAR, e um N.º de processo repetido dentro do próprio ficheiro. Uma linha por
identificar não é um erro — é uma pergunta —, mas nenhuma grelha sai enquanto
ela estiver em aberto.

## `inovar_code` é o valor exportado para o Inovar

As colunas qualitativas da grelha recebem o **`inovar_code` da banda**: `B`,
`MB`, `S`, `I`, `F`. Nunca o `code` da escala («4»), nunca o rótulo («Bom»).
`code` é o nome curto que a escala dá à banda e vale outra coisa na escala
seguinte; `label` é texto de autor que uma tradução parte. Uma escala sem
correspondência declarada **não é exportável**, e dizê-lo é a resposta —
inventar um código poria em frente de uma escola uma classificação que nenhuma
regra aprovada produziu ([`InovarCodeResolver`](../app/Services/Export/InovarCodeResolver.php)).

**Onde o professor decidiu a apreciação de um domínio, é a decisão dele que
viaja** — pelo `inovar_code` da banda decidida. Onde não há decisão, vai o valor
canónico de sempre; nada é inventado por causa disto.

A **coluna do nível** é outra coisa e continua a seguir a sua regra: leva o
`code` da escala («3», «4», «5»), que é o vocabulário dessa coluna, e só quando
o professor escolhe a coluna — nunca «a coluna a seguir aos domínios».

## O Excel é do Lapispro, não é a grelha do Inovar

Servem coisas diferentes. A grelha do Inovar preenche o ficheiro que a escola
forneceu e o seu único critério é caber lá dentro. O Excel da pauta serve
arquivo e leitura humana, e por isso tem título, contexto e a leitura da própria
pauta: cabeçalho em dois níveis com os domínios agrupados, painéis fixos,
autofiltro, larguras próprias, números como números e níveis como texto.

**A cor identifica o domínio, nunca o desempenho.** É a mesma decisão da grelha
no ecrã: um verde ao lado de um número passaria a significar «bom», e o ficheiro
começaria a emitir juízos que ninguém escreveu. E nunca é a única informação —
cada domínio está escrito por extenso no cabeçalho.

## Levar a pauta para o Microsoft Teams

**Auditado a 2026-09-06: não existe qualquer infraestrutura Microsoft no
produto.** Nem Graph API, nem OAuth da Microsoft, nem OneDrive, nem SharePoint,
nem Socialite, nem App Registration, nem credenciais. `config/services.php`
tem Postmark, Resend, SES e um token de bot do Slack para notificações
internas — e mais nada. As únicas ocorrências da palavra «Microsoft» no código
são um namespace XML de VML no leitor de fotografias e uma menção legal ao dono
do GitHub.

Um «Enviar para o Teams» direto exigiria criar autenticação Microsoft de raiz —
App Registration, segredos, consentimento da organização, uma dependência
grande — o que é precisamente uma das decisões que o CLAUDE.md manda **não tomar
sem perguntar**. Fica por implementar, e deliberadamente.

O que já está pronto:

- **CSV e XLSX são formatos que o Teams abre nativamente.** Um professor
  descarrega o ficheiro e larga-o no canal ou na equipa — que é hoje o caminho
  real, e não precisa de nada da nossa parte.
- **O ponto de extensão está desenhado.** A cadeia é
  `EvaluationSheetDocument` → escritor (`…CsvWriter` / `…XlsxWriter`) → bytes →
  resposta HTTP. Um destino futuro entra no último passo e em mais lado nenhum:
  um `EvaluationSheetDestination` com um método que recebe o documento (para o
  nome e o contexto) e os bytes, e devolve onde ficaram. O controlador passaria
  a escolher entre «descarregar» e «enviar para \<destino\>»; nem o documento nem
  os escritores mudam uma linha.
- **O que faltaria** é só a autenticação e a escolha do destino (equipa, canal,
  pasta) — nenhuma das duas é uma decisão técnica desta fatia.
