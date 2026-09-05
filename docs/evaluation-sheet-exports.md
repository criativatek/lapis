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
