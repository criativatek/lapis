# Análise estatística e relatórios — fase 1: Resultados do instrumento

**Branch:** `feat/assessment-results-analysis` · **Base:** `origin/main` 0.154.3 (`0fa28750`)
**Versão alvo:** 0.155.0 · **Estado:** especificação aprovada para a primeira entrega

## 1. O que já existe (e não se recria)

| Peça | O que faz | Uso nesta fase |
|---|---|---|
| `CalculationEngine` | Motor puro: domínios ponderados pelos pesos do perfil, `absence_mode`, bónus fora do denominador, banda casada sobre o valor **exato** (truncado a 6 casas), arredondamento único na proposta. | **Fonte única** dos valores por aluno. Não é alterado. |
| `ClassResultsCalculator::forInstruments()` | Resultado de UM aluno em cada instrumento, pelo mesmo motor e regras congeladas do perfil; aplica a elegibilidade por data (entrada tardia, saída, janelas de não-frequência). Já usado pela Evolução do Aluno e pelo `AccumulatedBreakdown`. | Chamado por matrícula. Não é alterado. |
| `ScaleProposalResolver::bandFor()` | Casa um valor normalizado com um nível da escala (limites inclusivos). | Apreciação qualitativa por aluno/domínio. |
| `BuildClassStatistics` (Estatística da turma) | Estatística **por período** sobre a progressão (resultados de período, classificações atribuídas). | Não é tocado. É o futuro consumidor do contexto C (ver §7). |
| `instrumentQualitativeRating.ts` (grelha) | Indicador de correção **no cliente**: pontos obtidos / pontos possíveis dos itens avaliados, arredondado a 1 casa, banda casada sobre o valor **arredondado**, sem pesos de domínio nem `absence_mode`. | Não é tocado. Divergência documentada (§8, Q2). |
| `DistributionBands`, `chartTheme`, `qualitativeTone` | Linguagem visual da Estatística. | Reutilizados os tons (`qualitativeToneFor`). |

**Não há segundo motor.** A análise nunca soma pontos nem pondera domínios: lê
`CalculationOutcome::normalizedValue` (global) e `DomainOutcome::normalizedValue`
(por domínio) tal como o motor os devolve, e faz por cima só estatística
descritiva (contagens, média, mediana, distribuições).

## 2. Arquitetura

```
app/Domain/Assessment/Analysis/            (puro — sem Eloquent, sem datas)
  ObservationStatus.php     enum: classified, pending, under_review, absent,
                            absent_justified, exempt, not_applicable, annulled,
                            out_of_scope
  Observation.php           um aluno numa dimensão de análise: estado + valor exato
  AnalysisBand.php          uma banda da escala (id, código, rótulo, min, max,
                            sequência, negativa)
  ResultsAnalyzer.php       estatística descritiva de uma lista de Observations
  DescriptiveReport.php     relatório determinístico a partir da análise

app/Services/Assessment/Analysis/          (adaptadores — Eloquent)
  ResultsContext.php        interface: o que qualquer contexto (A/B/C/D) entrega
  InstrumentResultsContext.php   contexto A (e D: diagnóstico é um instrumento
                                  com purpose=diagnostic)
  BuildResultsAnalysis.php  orquestra: contexto → analisador → relatório → payload

app/Models/ResultsAnalysisNote.php         observações do professor (persistidas)
app/Http/Controllers/InstrumentResultsController.php
resources/js/pages/instruments/Results.vue, instruments/results/Print.vue (sufixo /Print: sem layout da app)
resources/js/components/analysis/*.vue
```

`ResultsContext` expõe: identificação (título, turma, período, tipo, data,
estado, finalidade), `classificatory(): bool`, dimensões (global + domínios
tocados pelo contexto), as `Observation` por dimensão, a escala (bandas) e
notas metodológicas. Os contextos B (intercalares) e C (finais de
período/semestre) implementarão a mesma interface lendo, respetivamente, o
snapshot da intercalar e a progressão de período — sem tocar no analisador nem
no relatório. **Nesta fase só existe `InstrumentResultsContext`.**

## 3. Regras de cálculo

1. **Valor por aluno** = `normalizedValue` do motor para aquele instrumento
   (percentagem exata, 6 casas). Por domínio: `DomainOutcome::normalizedValue`
   dos domínios **tocados pelos itens do instrumento** (os restantes domínios do
   perfil não são dimensões deste instrumento).
2. **Estado do aluno** (derivado da explicação do motor, nunca de uma segunda
   leitura das células):
   - valor não nulo → `classified` (marcado *parcial* se algum item ficou
     excluído por `pending`/`under_review`);
   - todos os itens excluídos por `not_applicable_to_enrollment` → `out_of_scope`
     (não frequentava a turma/disciplina na data) — fica fora do universo;
   - caso contrário, precedência: `pending` > `under_review` > `absent` >
     `absent_justified` > `exempt` > `not_applicable` > `annulled`.
   - Uma ausência contada como zero por `absence_mode` (`zero_all`,
     `zero_unjustified_only`) **é** uma classificação: é a regra do perfil.
3. **Universo** = matrículas de `ClassCohort::enrollmentsFor()` menos
   `out_of_scope`. **Avaliados** = `classified`. Todos os indicadores usam como
   denominador os avaliados da dimensão, e cada bloco declara o seu `N`.
4. **Limiar 49,5 %** aplicado ao valor exato: `< 49,5` abaixo; `≥ 49,5` igual
   ou superior. Nunca ao valor arredondado.
5. **Média** = média aritmética dos valores exatos (bcmath), **mediana** =
   valor central/ média dos dois centrais, ambas arredondadas só para
   apresentação (1 casa, `half_up`, `BuildResultsProgression::PRECISION`).
6. **Distribuição quantitativa**: 10 classes `[0, 10[ … [80, 90[ [90, 100]`
   sobre o valor exato. (Bónus pode dar > 100: entra na última classe.)
7. **Distribuição qualitativa**: uma categoria por nível da escala **do perfil
   da turma** com bandas (`band_min_normalized`/`band_max_normalized`), na
   ordem da escala, casada sobre o valor exato; valores fora de todas as bandas
   contam como «sem apreciação». Escala sem bandas → distribuição qualitativa
   indisponível, dito explicitamente. Nunca intervalos fixos.
8. Nada é escrito: nenhuma classificação, snapshot ou perfil é alterado.

## 4. Diagnóstico

- `purpose = diagnostic` → contexto diagnóstico: mesma escala, mesmos
  cálculos, relatório orientado para potencialidades/dificuldades/acompanhamento,
  e a vista afirma que **não contribui para médias classificativas**.
- **Problema existente (documentado, não corrigido aqui):** a exclusão não é
  garantida. `counts_toward_classification` é o único portão do motor
  (`ClassResultsCalculator::instrumentsInScope`) e para diagnósticos é apenas um
  *default* (`InstrumentBuilder::applyDiagnosticDefault`, `InstrumentForm.vue`),
  que o professor pode mudar; a importação de grelhas grava o valor recebido; o
  docblock de `Instrument` diz mesmo «A diagnostic instrument may still count if
  the teacher decides so». Um diagnóstico marcado como contando **entra** no
  período. A vista Resultados mostra um aviso nesse caso; um teste de
  caracterização fixa o comportamento atual. Corrigir exige decidir o que fazer
  aos instrumentos já gravados assim (Q1) — intervenção própria.
- **Resolvido pela PR B (#43, JANELA AG), integrada nesta branch.** Um
  diagnóstico nunca conta: `InstrumentEligibility` exclui-o no motor, na
  publicação e na prontidão, seja qual for o valor gravado, e o modelo grava-o
  sempre como «não conta» (criação, edição, importações, restauro). O aviso e o
  teste de caracterização foram retirados. Q1 ficou decidida pelo proprietário:
  os diagnósticos já gravados estavam todos como «não conta», por isso não há
  regularização a fazer.

## 5. Relatório descritivo

Determinístico (sem IA), gerado a partir da análise, por esta ordem:
identificação do contexto · síntese global · distribuição quantitativa ·
distribuição qualitativa · resultados por domínio · principais diferenças
estatísticas observadas · observações do professor.

«Diferenças observadas» só afirma factos estatísticos: domínio com média mais
alta/mais baixa e a diferença em pontos percentuais; domínio com maior
proporção abaixo do limiar; diferença média–mediana ≥ 5 p.p. (assimetria);
classificações em falta; resultados parciais; `N < 5` → cautela. **Nunca
atribui causas.**

O relatório para o diretor de turma **não inclui nomes nem classificações
individuais por defeito**; a inclusão é uma opção explícita da vista de
impressão.

**Observações do professor** vivem numa tabela própria
(`results_analysis_notes`), separadas dos indicadores, que são sempre
recalculados na leitura: recalcular nunca as toca. Concorrência otimista por
`lock_version`.

**Migração justificada:** não há onde guardar este texto sem misturar
semântica — `instruments.internal_notes` é a nota do próprio instrumento
(formulário, exportações), e o módulo de Relatórios não tem âmbito
«instrumento». A tabela tem `context_kind` para os contextos futuros.

## 6. Apresentação

Separador «Resultados» ao lado da grelha de correção
(`/instruments/{ulid}/resultados`), por esta ordem: resultados por aluno →
indicadores (Global | domínio) → gráficos + tabelas → resultados por domínio →
relatório. Gráficos em HTML/CSS (sem Chart.js): título, categorias, número e
percentagem escritos em cada barra, total `N` na legenda, barras abaixo do
limiar com padrão tracejado além da cor, tabela com os mesmos dados, legíveis
em telemóvel e na impressão. Relatório imprimível em
`/instruments/{ulid}/resultados/relatorio`.

## 7. Fora desta entrega

Contextos B (intercalares) e C (finais) — só a interface; evolução diagnóstico
→ sumativo; finalização/snapshot do relatório (V3-15-023); exportação
RGPD/backup das observações; DOCX/PDF próprios; IA.

## 8. Questões para decisão do utilizador

- **Q1.** Tornar a exclusão diagnóstica uma garantia (portão no motor ou
  validação) e o que fazer aos diagnósticos já gravados como «conta».
- **Q2.** A grelha mostra um indicador de pontos (sem pesos de domínio, banda
  sobre o valor arredondado); Resultados mostra o valor do motor. Alinhar a
  grelha ao motor?
- **Q3.** O limiar 49,5 % é fixo nesta fase (coincide com o início de
  «Suficiente» na escala 1–5 de sistema). Deve passar a derivar da escala?

## Anexo A — Contrato do payload (Inertia `instruments/Results` e `instruments/results/Print`)

Valores numéricos viajam como **strings decimais com ponto** (`"72.4"`), já
arredondados a 1 casa para apresentação; `exact` leva o valor do motor (6
casas). O cliente só formata (vírgula decimal, `%`). Percentagens de contagem
(`percent`) idem, 1 casa. `null` = sem valor, nunca zero.

```ts
type Band = { key: string; code: string; label: string; sequence: number; is_negative: boolean };
type Cell = { value: string | null; exact: string | null; band: Band | null; below_threshold: boolean | null; is_partial: boolean };
type Count = { count: number; percent: string | null };

type Analysis = {
  universe: number;            // abrangidos (exclui out_of_scope)
  classified: number;          // avaliados = N de todos os indicadores
  partial: number;             // avaliados com itens por corrigir
  out_of_scope: number;
  missing: { total: number; pending: number; under_review: number; absent: number;
             absent_justified: number; exempt: number; not_applicable: number; annulled: number };
  mean: string | null; median: string | null; min: string | null; max: string | null;
  threshold: { value: string; below: Count; at_or_above: Count };
  quantitative: { total: number; classes: Array<{ key: string; label: string; count: number; percent: string | null; below_threshold: boolean }> };
  qualitative: { available: boolean; total: number; unplaced: number;
                 categories: Array<Band & Count> };
};

type Props = {
  context: {
    kind: 'instrument';
    is_diagnostic: boolean;
    classificatory: boolean;                 // !is_diagnostic
    counts_toward_classification: boolean;
    instrument: { ulid: string; title: string; applied_on: string; status: string; status_label: string;
                  type: string | null; purpose: string; purpose_label: string };
    class: { ulid: string; label: string };
    period: { label: string };
    absence_mode: string; absence_mode_label: string;
    threshold: { value: string; label: string };      // "49.5", "49,5 %"
    scale: { name: string | null; has_bands: boolean; bands: Band[] };
    domains: Array<{ key: string; id: number; name: string; weight_percent: string | null }>; // key = "d{id}"
    items_without_domain: number;
    notes: string[];                         // notas metodológicas já redigidas
  };
  students: Array<{ enrollment_id: number; class_number: number | null; name: string;
                    status: string; status_label: string;
                    global: Cell; domains: Record<string, Cell | null> }>;
  dimensions: Array<{ key: 'global' | string; label: string; analysis: Analysis }>;
  report: { title: string; generated_at: string;
            sections: Array<{ key: string; title: string; paragraphs: string[];
                              table: { columns: string[]; rows: string[][] } | null }> };
  note: { body: string; lock_version: number; updated_at: string | null; updated_by: string | null };
  can_edit: boolean;
  include_individual: boolean;               // só na página de impressão
  links: { grid: string; results: string; report: string; report_with_individual: string; note: string };
};
```

`students` só é enviado à página de impressão quando `include_individual` é
verdadeiro; caso contrário é `[]` (o agregado não carrega nomes nem valores
individuais, nem sequer escondidos no HTML).

---

## 9. Revisão pré-integração — regras definitivas A–D (2026-09-25)

As questões Q1–Q3 da §8 foram decididas pelo proprietário do produto. Esta
secção prevalece sobre o que acima a contradiga (em particular §3.4, o limiar
fixo, e o Anexo A).

### Regra A — só instrumentos concluídos têm resultados oficiais
- `InstrumentStatus::isConcluded()` = `completed` ou `published` (`published`
  só chega por restauro de backup e é posterior à conclusão). `archived` **não**
  conta como concluído: nunca é escrito pelo código e não se sabe em que estado
  da correção um instrumento restaurado assim ficou.
- Resultados e a versão para impressão só calculam e mostram estatísticas,
  gráficos e relatório para um instrumento concluído. Antes disso mostram o
  estado e uma explicação e nada calculam (o motor nem é chamado). As
  observações do professor continuam visíveis e editáveis.
- Reabrir a correção retira o instrumento dos resultados oficiais. Não apaga
  notas, observações nem classificações: a transição só muda o estado.
- **O que ainda NÃO cumpre a regra:** o motor continua a admitir instrumentos
  `prepared`/`in_correction` nas médias de período e acumuladas
  (`InstrumentStatus::entersCalculation`). Ver §10, PR B.

### Regra B — diagnósticos nunca contam
- A interface deixa de afirmar a exclusão como garantida: diz o que a
  configuração do instrumento determina. Se o instrumento não conta, diz que
  não entra nas médias do período. Se conta, mostra um aviso explícito.
- **A garantia no motor não está implementada nesta PR** (§10, PR B).

### Regra C — grelha e Resultados coerentes
- A grelha passa a mostrar duas grandezas com nome próprio. A **pontuação
  bruta** (pontos/cotação, ao vivo, sem apreciação) e a **classificação
  oficial** (ou **provisória**, enquanto a correção não estiver concluída),
  calculada pelo mesmo caminho que Resultados
  (`BuildResultsAnalysis::officialCells`). A apreciação sai sempre do valor
  exato. Quando o arredondamento a uma casa sugeriria outra banda ou o outro
  lado do limiar, o valor é mostrado truncado a duas casas
  (`value_precise`).
- Uma linha com alterações por guardar é marcada «guarde para recalcular»: o
  valor oficial reflete só o que está gravado.

### Regra D — limiar da escala
- `ScaleThreshold::from()` devolve a fronteira entre bandas negativas e não
  negativas (`is_negative`): o `band_min` da primeira não negativa, **só** se
  todas as negativas estiverem inteiramente abaixo de todas as não negativas.
  Caso contrário (sem marcas, intercaladas, sobrepostas, sem bandas) devolve
  `null`. Nesse caso nenhum limiar é apresentado e a página di-lo. Na escala
  1–5 de sistema dá 49,5.

### Alterações ao Anexo A
- Topo: `availability: { official, status, status_label, message }`. Quando
  `official` é falso: `dimensions = []`, `students = []`, `report = null`.
- `context.threshold: { value: string|null; label: string|null; explanation }`.
- `Analysis.threshold: { value: string|null; available; below: Count|null; at_or_above: Count|null }`.
- `Cell.value_precise: string|null`. `below_threshold` é `null` sem limiar.
- Grelha: prop `official: { status: 'official'|'provisional'; label; threshold; domains; students }`.

## 10. Impacto no motor e divisão do trabalho

### Consumidores do cálculo (auditoria de 2026-09-25)
`instrumentsInScope` (período e acumulado) alimenta:
- Resultados (Média Ponderada, ⚠, evolução) e a análise IA de Resultados;
- `BuildResultsProgression` e, a partir dele, Estatística, Evolução do Aluno e
  relatórios não intercalares;
- `FormalProposalBasis`, `ProposeClassifications` e a verificação de proposta
  desatualizada em `ConfirmClassification`;
- Pauta (`BuildEvaluationSheet`, CSV) e Quadro Síntese (+ XLSX);
- `AccumulatedBreakdown` e `ContinuousAssessment`;
- a captura de intercalares (congelada depois);
- a exportação Inovar do período corrente e as colunas «Média Ponderada» da
  exportação de dados;
- a pré-visualização de migração de perfil.

`PublishClassifications` e `EvaluationSheetReadiness` **repetem** o filtro em
vez de o chamarem. `forInstruments` e `BuildClassElements` não filtram nada.

### Proteção histórica existente
- Congelados: `final_*`/`proposed_*` de classificações confirmadas/publicadas,
  `CalculationSnapshot`, `InterimAssessment`, `EvaluationSheetExport` e
  relatórios finalizados.
- Recalculados ao vivo, para qualquer período, incluindo passados: tudo o
  resto. O estado «Encerrado» de um período existe, mas **nada o consulta**, e
  não há ação que o aplique. Os snapshots de cálculo nunca são relidos.

**Consequência.** Mudar a elegibilidade no motor alteraria retroativamente
valores já vistos por professores, para períodos passados. Faria ainda com
que classificações confirmadas aparecessem como «proposta desatualizada» e
que propostas por confirmar fossem recusadas. Isto cai no critério de paragem
(regras já usadas em produção; períodos passados), por isso não entra na PR #42.

### PR B — elegibilidade no motor (obrigatória antes da publicação)

> **Estado (2026-09-26) — decisão definitiva do proprietário:** PR #43,
> integrada nesta branch, garante só que **um diagnóstico nunca conta**. A
> exigência de correção concluída (critério 1 abaixo) foi **abandonada**: os
> instrumentos normais contam como sempre, com «Conta para a classificação»
> ativada, estejam ou não concluídos. Sem migrações, colunas nem transição
> histórica. O que se segue neste bloco fica como registo da análise.
Âmbito:
- regra única de elegibilidade num só sítio: concluído **e** não diagnóstico
  **e** `counts_toward_classification`, em `ClassResultsCalculator` e nos dois
  guardas que repetem o filtro;
- validação no servidor (formulário, criação rápida, importação de grelhas)
  que recusa um diagnóstico que conte;
- o restauro de backup preserva os dados, mas não os torna elegíveis.

Critérios de aceitação:
1. Um instrumento não concluído não entra em médias de período ou acumuladas.
2. Um diagnóstico nunca entra, seja qual for a opção guardada.
3. As classificações confirmadas/publicadas, os snapshots, as intercalares e as
   pautas guardadas não mudam (testes de byte a byte).
4. Os dois guardas usam a mesma regra.
5. Um relatório de impacto sobre dados fictícios mostra o que muda nos
   períodos abertos.

Decisões necessárias antes de a abrir:
- (a) se a regra se aplica também a períodos passados ou só daqui em diante, o
  que exige introduzir o fecho de período, hoje inexistente;
- (b) o que fazer às propostas `Proposed` que ficariam desatualizadas;
- (c) como regularizar os diagnósticos já gravados como «conta». Proposta:
  listar e não reescrever; os que estão em curso passam a não contar; os de
  períodos com classificações confirmadas ficam como estão e são sinalizados.

Também é preciso saber quantos instrumentos isto afeta em produção: uma
contagem só de leitura, a autorizar.

### PR C — observações na exportação e no backup (obrigatória antes da publicação)
Incluir `results_analysis_notes` na exportação de dados (XLSX/JSON) e no
backup/restauro, com uma nova versão do esquema.

Critérios de aceitação:
1. A exportação contém as observações.
2. Um restauro reproduz-as, com autoria.
3. Um backup antigo continua a restaurar.

### Evoluções posteriores
Relatórios de intercalares e finais (com a mesma Regra A); comparação entre
diagnóstico e avaliações posteriores; PDF/DOCX; IA.
