# Análise Avançada da Avaliação (Pro) — fundação (D0)

Estado: **D0 — fundação**. Sem ecrã, sem rota, sem alteração visível a nenhum plano. A página
«Análise da Avaliação» e tudo o que a usa chegam nas etapas seguintes (D1–D6), cada uma com
autorização própria.

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

## Etapas seguintes (não autorizadas)

D1 comparação entre períodos da turma · D2 comparação por aluno · D3 pontos fortes e dificuldades ·
D4 tendências e dificuldade persistente · D5 documento Pro · D6 validação, ajuda, texto comercial, 0.176.0.
