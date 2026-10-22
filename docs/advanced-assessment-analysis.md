# Análise Avançada da Avaliação (Pro) — fundação (D0)

Estado: **D0 — fundação**, **D1 — comparação da turma** e **D2 — «Ao longo do ano» do aluno**
(integradas) e **D3 — pontos fortes e dificuldades da turma** (esta etapa). As seguintes (D4–D6) chegam
cada uma com autorização própria.

## Decisões aprovadas (Fatia D)

1. Estende-se o que o Pro já tem; a página «Análise da Avaliação» vive na área de Avaliação.
2. Eixo temporal principal: os **períodos**; momentos guardados entram só quando os dados são comparáveis.
3. Regra de evolução inalterada («qualquer alteração à precisão mostrada»), acrescida da **mudança de nível**.
4. Resultado negativo persistente = dois momentos consecutivos, incluindo o mais recente. Não é uma tendência.
5. Tendência = pelo menos **3 pontos**; nota de cautela com menos de **5 alunos**.
6. Listas nominais só para professores autorizados; linguagem factual e respeitadora.
7. Documento Pro (pré-visualização, impressão, PDF).
8. **Não comparar escalas ou critérios diferentes sem demonstrar a comparabilidade.**
9. Protegida pelo módulo `advanced_analytics`.

## O que a D0 entrega

| Peça | Papel |
|---|---|
| `App\Services\Assessment\Advanced\BuildAssessmentTimeline` | A turma ao longo do tempo: pontos (períodos + momentos guardados), células por aluno, disponibilidade e avisos. |
| `TimelineCriteria` | As duas **assinaturas**: escala (tipo + níveis por ordem) e critérios (domínios + pesos). |
| `TimelineComparability` | Diz se dois pontos são comparáveis e, se não, **porquê**, em texto legível. |
| `TimelineMovement` | O movimento entre dois números (a regra existente) e a mudança de nível. |
| `AdvancedAnalyticsUnavailable` | O **serviço** recusa uma organização sem `advanced_analytics`, além da rota. |
| `App\Support\Assessment\ScaleSummary` | Um só escritor da forma «escala que viaja» (Pauta e linha temporal). |
| `BuildResultsProgression::movement()` | A regra de evolução existente, extraída para função pública e estática. `evolution()` chama-a: mesmo resultado, byte a byte. |

Nenhuma classe do motor de cálculo foi alterada: a linha temporal **lê** a `BuildResultsProgression`
e as fotografias guardadas; não calcula nada de novo.

## Princípios que o código faz cumprir

- **Os números são os de sempre.** Teste de paridade valor a valor com a progressão (resultado,
  domínios, nível atribuído, movimento).
- **Um momento guardado é o que foi guardado.** Nunca lê o presente; mudar a decisão da professora
  não move um único número de uma fotografia (teste).
- **Demonstrar, nunca presumir.** Uma fotografia de antes de a escala viajar com ela não tem
  assinatura; `desconhecido` nunca é igual a `desconhecido`. Mudou a escala → níveis recusados e
  percentagens mantidas; mudaram os pesos → percentagens recusadas e níveis mantidos. A razão viaja
  sempre com a recusa.
- **Um nível só tem direção na escala que o define** (ordem `sequence`, linha `is_negative` da própria
  escala); nunca pelo texto da etiqueta.
- **Dados suficientes, ditos com clareza.** `availability` diz quantos pontos comparáveis existem e
  porque ainda não há tendência (precisa de 3). `few_students` assinala períodos com menos de 5 alunos
  com resultado.
- **Privacidade.** Sem identificadores numéricos, sem texto livre (motivos de override), sem
  autoavaliação. Alunos por `ulid` e posição.
- **Custo constante.** O número de queries não cresce com a turma (6 vs 30 alunos: igual).
- **Isolamento.** Tudo corre no âmbito da organização; o serviço recusa sem organização em contexto.

## Limites conhecidos do horizonte de dados

- Em produção só existe hoje o 1.º período com dados; os momentos guardados são poucos.
- Períodos «ao vivo» são recalculados com o perfil e os dados **de hoje** — dizem-no (`basis`).
- Fotografias anteriores à 0.174/0.175 não trazem a escala: comparam percentagens, não níveis.
- Uma mudança de perfil (domínios/pesos/escala) interrompe a comparabilidade e é dita.

## Isolamento do plano Base

`tests/Feature/Assessment/Analysis/BasePagesCharacterizationTest.php` congela, para as quatro
páginas que a análise vai acompanhar (Estatística, Evolução do Aluno, Evolução da Turma, Pauta),
os caminhos de chaves e um hash das props próprias de cada página, em Base **e** em Pro. As
fixtures estão em `tests/Fixtures/AdvancedAnalysis/` (`-text` em `.gitattributes`) e foram
capturadas **antes** de qualquer alteração da D0. Se uma etapa futura mudar o que o Base vê, este
teste falha e obriga a decidi-lo de propósito. Garante também que uma página Base não traz nenhuma
chave da camada avançada, hoje ou amanhã.

`BuildAssessmentTimelineTest::nothing_in_the_application_uses_the_timeline_yet…` garante que, na D0,
nenhum controlador, rota ou componente usa a linha temporal; a primeira etapa que o fizer tem de
juntar o `module:advanced_analytics` e atualizar esse teste de propósito.

## D1 — Comparação da avaliação da turma

**Onde:** `GET classes/{class}/analise-avaliacao` (`assessment-analysis.show`), página «Análise da
Avaliação», dentro de Resultados. Entrada: «Comparar momentos →» no cabeçalho da Estatística (só com o
módulo disponível para leituras novas). Query opcional `de` e `para` (chaves de momento
`period:<ulid>` / `moment:<ulid>`); um valor mal formado é erro de validação, um valor válido mas
inexistente cai no default.

**Pro, em três níveis:** a rota leva `module:advanced_analytics`; o controlador volta a perguntar ao
`Entitlements` (a rota deixa passar um GET de uma organização suspensa, e a análise nova não se produz
nesse estado → 403 «não incluída»); os serviços recusam (`AdvancedAnalyticsUnavailable`). O Base não
chega ao controlador e a resposta 403 não leva nenhum dado da turma.

**`BuildAssessmentComparison`** lê a linha temporal e não tem aritmética própria além de somar e
dividir:
- *Seleção:* por omissão, o momento mais recente com resultados contra o mais próximo, anterior, com o
  qual se demonstra comparabilidade (se nenhum, o anterior mais próximo, para a página dizer porquê).
  Uma escolha explícita é respeitada e ordenada no tempo; nunca é trocada em silêncio.
- *Evolução da turma:* média dos MESMOS alunos nos dois momentos (os que têm resultado em ambos);
  a variação é `BuildResultsProgression::movement()` aplicada a essas duas médias. A média de cada
  momento (todos os que nele têm resultado) aparece à parte. A média por momento é a mesma soma-e-divisão
  da Estatística (teste de paridade).
- *Contagens* progrediram / mantiveram-se / regrediram: por aluno, a regra de movimento existente
  (qualquer alteração na casa decimal mostrada). Paridade com a `evolution` da progressão, para a turma
  e para cada domínio.
- *Variação:* pontos percentuais (p.p.), nunca «percentagem da percentagem».
- *Níveis:* do resultado (faixa) e atribuído (decisão do professor); subiu/manteve/desceu na ordem da
  escala; «passou a positivo/negativo» na linha da própria escala; distribuição por nível nos dois
  momentos. Só com a escala demonstrada igual.
- *Sem comparação artificial:* critérios diferentes → os números aparecem lado a lado, sem variação,
  sem contagens, com a razão; escala desconhecida/diferente → só os níveis são recusados; um aluno com
  resultado num só extremo é «sem comparação» (nunca zero) e entra na contagem `not_compared`.
- *Origem e cautelas:* cada momento diz de onde vêm os dados («calculado com os dados de hoje» /
  «guardado a …»), a cobertura (alunos com resultado, cobertura parcial) e há nota quando os momentos
  são de tipo diferente (a diferença inclui correções entretanto feitas) e quando há menos de 5 alunos.
- *Explicações:* sete textos curtos («como se calcula»), do servidor.

**Privacidade:** lista nominal para quem pode `view` a turma (os seus professores), tal como a
Estatística e a Evolução do Aluno; sem ids numéricos, sem texto livre, sem autoavaliação.

**Desempenho:** a comparação é aritmética sobre a linha temporal já em memória (no máximo a pergunta
do plano); a página custa o mesmo número de queries com 6 ou 30 alunos.

**Testes:** `AssessmentComparisonTest` (serviço + página), `Show.test.ts` e `assessmentComparison.test.ts`.
O teste de arquitetura da D0 passou a exigir que toda a rota que chega ao controlador leve
`module:advanced_analytics`. As fixtures de caracterização do Base não mudaram (a entrada na
Estatística usa as props partilhadas `modules` e `readOnlyModules`, sem props novas).

## D2 — «Ao longo do ano» na Evolução do Aluno

**Onde:** secção exclusiva Pro da página «Evolução do Aluno» (`classes/{class}/evolucao/{enrollment}`),
depois de «Em que domínios». Query opcional `de` e `para` (chaves `period:<ulid>` / `moment:<ulid>`),
validadas como na D1; a `leitura` da página mantém-se ao trocar de momentos.

**Pro, sem rasto no Base:** a prop `yearReading` só existe com `advanced_analytics` (nem para o Base,
nem para uma organização suspensa, para a qual `allows()` é falso); a linha temporal nem sequer é
construída. O serviço `BuildStudentYear` recusa de seguida (`AdvancedAnalyticsUnavailable`). O teste de
caracterização do Base não mudou; o do Pro (`student-pro`) foi regenerado de propósito — acrescenta só
`yearReading.*` — e, com essa chave removida, a página Pro é byte a byte a de antes.

**`BuildStudentYear`** (sobre a linha temporal, sem aritmética própria):
- usa `BuildAssessmentComparison::studentRow()` — a função que a página da turma usa para o mesmo
  aluno — e `select()` para a escolha dos dois momentos: turma e aluno nunca discordam sobre quem subiu
  (teste de paridade, e paridade com a `evolution` da progressão);
- só oferece os momentos em que ESTE aluno tem resultado; por omissão, os dois últimos;
- *não repete o que a página já diz:* «Como evoluiu» (últimos dois momentos na leitura escolhida) e
  «Em que domínios» (acumulado e última variação) ficam como estão. A secção acrescenta a escolha livre
  de dois momentos, a mudança de NÍVEL (e a travessia da linha da escala), cada domínio em todos os
  momentos, e a origem dos dados e os limites de comparabilidade;
- *outra leitura, dita:* usa o resultado de cada período por si (o de Resultados), não a leitura
  contínua; a secção e a explicação «Que resultado é este» dizem-no;
- *sem comparação artificial:* critérios diferentes → números lado a lado, sem variação e com a razão;
  escala desconhecida/diferente → só os níveis são recusados; um domínio ou resultado em falta num
  extremo é «sem comparação», nunca zero;
- *gráfico factual:* resultado global e domínios nos momentos que partilham os critérios do último
  (círculo = fim de período, quadrado = momento guardado); sem valor há uma lacuna, nunca um zero; os
  momentos que ficam de fora dizem-se; com menos de dois pontos não há gráfico, há uma frase. Tabela
  acessível com os mesmos números;
- *não é diagnóstico:* uma descida ou um nível negativo são dois números; não se procura persistência
  (é da D4) e não se sugere causa.

**Privacidade:** só as células deste aluno saem da turma; não viaja nenhum nome de colega nem a
contagem de colegas com resultado (que a página da turma mostra ao professor da turma). Mesmas regras
de acesso (`view` da turma; a matrícula tem de ser da turma do URL).

**Desempenho:** o número de queries da página é o mesmo com 6 ou 30 alunos.

**Testes:** `StudentYearTest` (serviço e página), `YearReading.test.ts`.

## D3 — Pontos fortes e dificuldades da turma

**Onde:** segunda secção da página «Análise da Avaliação» (`classes/{class}/analise-avaliacao`), abaixo
da comparação, com ligação interna no topo. Query `momento` (chave `period:<ulid>` / `moment:<ulid>`),
validada como `de`/`para`; as duas secções partilham o URL e cada uma conserva o que a outra mostra.
Pro nos mesmos três níveis da D1 (rota `module:advanced_analytics`, controlador, serviço).

**`BuildClassStrengths`** lê UM ponto da linha temporal — não precisa de um segundo momento — com a
escala e os critérios desse momento:
- *Linha entre positivo e negativo:* a do próprio momento (`band.is_negative`). Um momento guardado antes
  de a escala viajar com ele, ou uma escala sem níveis, não tem linha: não se contam alunos abaixo do
  nível positivo, e a página diz porquê (nunca se empresta a linha de hoje).
- *Contagens com denominador, sempre:* «8 de 27 alunos». Por domínio: média (a mesma soma-e-divisão da
  Estatística; teste de paridade com `period_series`), alunos com valor, alunos abaixo do nível positivo
  (n, total e proporção) e cobertura (`none` / `few` (<5) / `incomplete` / `complete`, com a cobertura
  parcial contada). Um domínio sem resultados é listado, não omitido.
- *Mais alta e mais baixa:* só com dois ou mais domínios com média; **num empate ninguém é destacado** e o
  empate é dito (empate, todos iguais, ou um só domínio → nada a destacar). Cada destaque diz o outro
  extremo («a mais baixa é …») para a afirmação assentar nos números mostrados.
- *Alunos com resultado abaixo do nível positivo em dois ou mais domínios:* distribuição (nenhum / um /
  dois ou mais, sobre os alunos com valor em pelo menos um domínio) e lista nominal só desses alunos, com
  os domínios e valores que a sustentam.
- *Pontos fortes e resultados a observar:* descrições dos números ao lado, cada uma com evidência
  (`{domain, count, of}`): média mais alta; domínios sem nenhum aluno abaixo da linha; média mais baixa;
  maior proporção abaixo da linha (comparada por produto cruzado, única, entre ≥2 domínios); alunos em
  dois ou mais domínios; cobertura (sem resultados / poucos alunos / parcial / incompleta). O que os
  dados não sustentam não se produz.
- *Não é diagnóstico nem recomendação:* «abaixo do nível positivo» descreve uma nota; nenhuma causa,
  previsão ou medida é sugerida (há um teste sobre as palavras do payload).

**Privacidade:** os únicos nomes que saem são os da lista dos alunos em dois ou mais domínios (e só para
quem pode `view` a turma); sem ids numéricos nem texto livre. **Desempenho:** aritmética sobre a linha
temporal em memória; o custo da página não cresce com a turma. O Base não muda (Estatística e restantes
páginas intactas; a página da análise devolve 403 sem estes dados).

**Testes:** `ClassStrengthsTest` (real + sintética), `StrengthsSection.test.ts`, `Show.test.ts`.

## Etapas seguintes (não autorizadas)

D1 ✔ comparação da turma · D2 ✔ aluno ao longo do ano · D3 ✔ pontos fortes e dificuldades ·
D4 tendências e dificuldade persistente · D5 documento Pro · D6 validação, ajuda, texto comercial, 0.176.0.
