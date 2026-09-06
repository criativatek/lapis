# Pauta de Avaliação — o que é proposta e o que é decisão

O Lapispro calcula. O professor decide. As duas coisas coexistem em todos os
ecrãs da Pauta e nenhuma apaga a outra — e é essa a única regra de que tudo o
resto neste documento decorre (§3.3).

## Três afirmações, todas verdadeiras ao mesmo tempo

Sobre o domínio «Escrita» de uma aluna, depois de o professor intervir:

| Afirmação | Quem a faz | Onde vive |
|---|---|---|
| **47,5 %** — quantitativo calculado | o motor de cálculo | derivado, nunca guardado numa decisão |
| **«2»** — proposta do Lapispro | `ScaleProposalResolver`, pela banda em que 47,5 % cai | derivado da escala, nunca copiado |
| **«3»** — decisão do professor | o professor | `domain_appreciation_decisions` |

O professor **não altera** os 47,5 %. O professor **não altera** a proposta. O
que ele altera é a leitura pedagógica que assume, e ela fica ao lado das outras
duas — nunca por cima delas.

**Quantitativo calculado ≠ apreciação decidida.** Um ecrã, um ficheiro ou uma
grelha que confundisse os dois estaria a dar por decidido o que ninguém decidiu.

## A decisão por domínio

`domain_appreciation_decisions` guarda **uma coisa**: o nível de escala que o
professor decidiu para um (matrícula, período, âmbito, domínio). Não guarda o
quantitativo, não copia a proposta, não guarda médias recalculadas e não guarda
nomes. Uma linha por decisão viva, e o que ficou para trás está no rasto de
auditoria.

- **Apagar a linha é voltar à proposta.** Não existe um estado «decidiu que é a
  proposta»: a ausência de linha significa exatamente «o professor não se
  pronunciou», e é isso que a pauta mostra em itálico.
- **Não entra em cálculo nenhum.** A decisão sobre «Escrita» não mexe na média
  ponderada dos domínios nem no resultado global. É uma leitura qualitativa
  daquele domínio, e o motor não sabe que ela existe.
- **É sempre reeditável.** Não há confirmação, não há publicação e não há fecho
  por domínio. Guardar a pauta, exportar para o Inovar ou ter fotografias no
  histórico não fecham porta nenhuma — são cópias do que era verdade num
  momento.
- **Deixa rasto**: `domain-appreciation.decided`, `.redecided`, `.cleared`, com
  o valor anterior, o novo, o domínio e quem decidiu.

No modelo de leitura da pauta, as duas metades têm chaves diferentes e ambas
viajam sempre:

- `scale_level_id` / `_code` / `_label` — a **proposta**. Continua a significar
  o que sempre significou, e é isso que mantém legível cada fotografia guardada
  até aqui.
- `decided_scale_level_id` / `_code` / `_label` — a **decisão**, `null`
  enquanto ninguém se pronunciar, e ausente nas fotografias anteriores a esta
  funcionalidade — que é a mesma coisa dita de outra maneira.

## Como se lê uma menção no ecrã

Uma menção é sempre um par: o código («4») e a menção qualitativa («Bom»). Qual
das metades aparece na célula depende de uma escolha do professor, não da
natureza da coisa — e é decidido num único sítio,
[`resources/js/lib/appreciation.ts`](../resources/js/lib/appreciation.ts):

| «Valores quantitativos» | Célula | Texto acessível |
|---|---|---|
| ligado | `4` | `4 — Bom` |
| desligado | `Bom` | `Bom — código 4` |

**A outra metade nunca desaparece.** Seja qual for a vista, a frase completa
chega a quem lê pelo `title` e pelo texto acessível — nada de essencial fica
dependente do rato. E a frase diz também **de quem é o juízo** («Decisão do
professor: …», «Proposta do Lapispro, ainda não decidida: …»), porque nem
negrito nem itálico são informação para quem não os vê.

Não há nesse ficheiro nenhuma tabela que converta «4» em «Bom»: os dois valores
vêm da escala configurada e viajam no modelo de leitura desde o servidor.

## Os dois momentos estruturais de cada unidade temporal

O topo da Pauta navega entre **momentos**, e não entre períodos. Cada unidade
temporal configurada tem dois, na ordem em que se vivem:

```
Intercalar 1.º Semestre | 1.º Semestre | Intercalar 2.º Semestre | 2.º Semestre
```

- Os rótulos derivam da configuração real do ano letivo. Uma escola com
  períodos vê «Intercalar 1.º Período»; nada no código sabe o que é um semestre.
- **Não é uma segunda noção de «intercalar».** As «Avaliações intercalares» — a
  fotografia estatística de uma turma numa data — continuam a ser o que eram.
  `SheetMomentKind` nomeia outra coisa: qual dos dois momentos a pauta está a
  preparar, uma distinção que já existia implicitamente, lida das datas do
  período por `InovarLevelOption`.
- **Os dois momentos leem exatamente a mesma pauta.** Nenhum número muda. O que
  muda é o título sugerido para a fotografia, o que «Preparar fecho» espera
  encontrar, e se a grelha do Inovar leva o nível por omissão.
- **Só os momentos estruturais são separadores.** Outras pautas guardadas,
  exportações repetidas, títulos personalizados: ficam no Histórico, que é onde
  um registo vive. Guardar mais nunca acrescenta separadores.
- Um momento **intercalar nunca fecha nada** — é a sua definição. Um momento
  **final** fecha quando o período chegou ao seu próprio `ends_on` ou alguém o
  fechou; enquanto ele corre, o separador do fecho existe para se **preparar** o
  fecho, não para tratar cada classificação por tomar como uma falta.
- O endereço por omissão continua a abrir o momento final: `?momento=interim` é
  o que muda de separador, e todas as ligações antigas continuam a apontar para
  onde apontavam.

## Cobertura: três estados, três frases

[`App\Support\Assessment\CoverageWording`](../app/Support/Assessment/CoverageWording.php)
é o único sítio onde a cobertura ganha palavras, e o ⚠ do ecrã lê a mesma regra:

| Estado | Quando | O que se diz |
|---|---|---|
| **Completa** | o motor não levantou bandeira | nada — não dar aviso É a mensagem |
| **Parcial** | há bandeira **e** há resultado | «Embora tenha havido avaliação neste domínio, nem todos os elementos previstos foram realizados.» |
| **Sem elementos** | há bandeira e **não** há resultado | «Ainda não há elementos avaliados neste domínio.» |

A concessiva é o ponto todo da frase da cobertura parcial: sem ela, «nem todos
os elementos previstos foram realizados» lê-se como uma queixa sobre o aluno.
E **nunca** pode ser dita sobre quem não teve avaliação nenhuma — afirmaria uma
avaliação que não existiu. É por isso que o estado se decide num sítio só, e que
«Preparar fecho», a fotografia guardada e o ⚠ do ecrã dizem todos a mesma coisa.
