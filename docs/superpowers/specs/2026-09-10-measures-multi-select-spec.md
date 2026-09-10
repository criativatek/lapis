# Estratégias e Medidas — seleção múltipla (spec de implementação)

Worktree: `D:\HERD\lapis-measures`, branch `feat/measures-multi-select` (a partir de `origin/main` @ 368ddc0, versão 0.140.4).
NÃO integrar em main. NÃO fazer deploy. Este documento é a base de trabalho — apaga-o ou mantém-no no fim, tanto faz, não é exigido pelo produto.

## Porque isto existe

Auditoria (explorer) + decisão estrutural (architect) já feitas. Resumo abaixo. Não redescutir as decisões de modelo — implementa-as. Podes discordar de detalhes de baixo nível (nomes de variáveis, etc.), mas o modelo de dados e os invariantes descritos aqui são vinculativos.

## MODELO ATUAL

Tabela `interventions` (migrations: `2026_07_23_000400_create_interventions_tables.php`,
`2026_08_14_000100_extend_interventions_for_flexible_targets_and_legal_framework.php`,
`2026_08_22_000100_add_pedagogical_reasoning_to_interventions.php`,
`2026_09_04_000100_add_purpose_and_tracking_to_interventions.php`).

Campos relevantes:
- `intervention_type` (string, `required` na validação do controller) — um valor do enum `App\Models\InterventionType` (30+ casos). Rotulado **"Intervenção"** no formulário (`resources/js/pages/interventions/Show.vue:754`), um único `<select>`.
- `support_measure_level` (nullable, CHECK: universal|selective|additional) — enum `App\Models\SupportMeasureLevel`.
- `support_measure_code` (nullable string) — enum `App\Models\SupportMeasureCode` (9 valores).
- `evaluation_adaptation_code` (nullable string) — enum `App\Models\EvaluationAdaptationCode` (10 valores). **NÃO TOCAR** — fora do âmbito desta fatia.
- `legal_mapping_source` (nullable, CHECK: system_direct|system_suggested_confirmed|manual).
- `participants()` — BelongsToMany via pivot `intervention_enrollment` (já suporta vários alunos por registo — não mexer).
- `status`, `started_on`, `concluded_on`, `review_on` — ciclo de vida por registo.
- `InterventionReview` (hasMany) — histórico de acompanhamentos/eficácia, por registo.

`InterventionType::requiresDescription()` → só `Other` exige descrição.
`InterventionController::resolveLegalFraming()` / `guardLegalFramingConflict()` usam `intervention_type` para sugerir automaticamente `support_measure_level`/`support_measure_code` via `App\Support\Interventions\InterventionLegalFramework` (implementação `PortugalInclusiveEducationFramework`).
`Intervention::pedagogicalTitle()` resolve por `strategy_label` → `title` → `intervention_type?->label()` (o tipo é *fallback* do título, não o título — já não está acoplado como se pensava inicialmente).

## LIMITAÇÃO ATUAL

1. **"Intervenção" (intervention_type) é seleção única** — o professor só pode escolher um tipo/medida pedagógica por registo, quando na prática um aluno beneficia de várias em simultâneo (ex.: Diferenciação pedagógica + Apoio psicopedagógico + Adaptação curricular significativa).
2. **Enquadramento pedagógico/legal é um único par** (`support_measure_level` + `support_measure_code`) por registo — não permite várias combinações nível+medida no mesmo registo.

## MODELO NOVO ESCOLHIDO

### A) "Medidas pedagógicas" (era "Intervenção") — Opção 1+: criação em lote, zero mudança de semântica por registo

**NÃO tornar `intervention_type` multi-valor no mesmo registo.** Cada `Intervention` continua com exatamente um `intervention_type`, um `status`, um ciclo de vida, um histórico de `InterventionReview` próprio — isto é deliberado (ver docblock de `InterventionType.php`: o tipo não é uma etiqueta trocável, é o dono de um ciclo de vida pedagógico independente; misturar vários tipos no mesmo registo obrigaria a escolher arbitrariamente qual comanda o enquadramento legal automático, o que seria inventar uma regra pedagógico-legal — proibido por §1/§31 do CLAUDE.md do projeto).

Em vez disso: **o formulário de CRIAÇÃO passa a permitir selecionar vários tipos/medidas em simultâneo** (multi-select pesquisável com chips, coerente com o padrão visual do resto da página). Ao submeter, o backend cria **N registos `Intervention`** — um por tipo selecionado — reutilizando os restantes campos do formulário (destinatário/participantes, data, descrição, `review_on`, `purpose`, `frequency`, `tracking_indicator`) para todos os N.

Schema: uma única coluna nova, nullable, sem FK:

```php
$table->ulid('created_batch_ulid')->nullable()->after('id');
$table->index('created_batch_ulid');
```

**Regra inegociável (escreve isto num docblock no model):** `created_batch_ulid` descreve **como o registo foi introduzido**, nunca **o que ele é**. Nunca deve ganhar uma relação Eloquent que propague estado, conclusão, enquadramento legal ou apareça em agregações de relatórios. É só metadado de proveniência para a UI poder dizer "registado em conjunto com outras N medidas" e oferecer uma ação de remover o lote inteiro (linha a linha, nunca um DELETE em massa disfarçado — remove cada `Intervention` do lote através do fluxo normal de eliminação, com o soft-delete e a auditoria que já existem).

Linhas antigas ficam `created_batch_ulid = null` — significa "introduzida individualmente", não é um valor a preencher.

**Invariantes a implementar:**

1. A seleção múltipla existe **só na criação**. `update()` continua a editar um único `intervention_type` por registo (como hoje) — não construir multi-seleção na edição.
2. Cada tipo repetido no mesmo payload é rejeitado (`distinct` na validação do array de tipos). `min:1`, `max:10`.
3. Em modo lote (mais de um tipo selecionado), **só é aceite enquadramento legal automático** (`legal_framing = 'auto'` ou ausente) — rejeitar no servidor (não só esconder na UI) se o payload pedir `legal_framing = 'manual'` ou `confirm_suggested_framing = true` quando `types` tem mais de 1 elemento. Cada registo resultante calcula o seu próprio enquadramento automático via `resolveLegalFraming()`, exatamente como hoje para um tipo único. O professor confirma manualmente depois, registo a registo, editando cada um.
4. Se `Other` estiver entre os tipos selecionados no lote, a descrição partilhada do formulário é obrigatória (como hoje para tipo único) e é gravada em todos os N registos criados. Não é preciso descrição por tipo nesta fatia.
5. **Duplicação:** tipos repetidos no mesmo payload → erro de validação. Um tipo já existente e aberto para os mesmos participantes com datas sobrepostas → **aviso**, não bloqueio (o professor decide, §3.3 do CLAUDE.md) — segue o padrão que já existir hoje para criação de intervenção única, se existir; se não existir aviso equivalente hoje, não inventar bloqueio novo nesta fatia.
6. **Atomicidade:** os N registos são criados numa única transação de BD. Se um falhar a validação/gravação, nenhum fica gravado.
7. **Auditoria:** um evento `intervention.created` por registo criado (não um evento de lote a esconder os N) — mas inclui `created_batch_ulid` nas `properties` de cada evento, para a trilha permitir reconstruir o gesto.
8. `participants()->sync()` e o `enrollment_id` legado repetem-se, coerentes, em cada um dos N registos.
9. Verifica se `StudentReportSource`/`ClassReportSource`/`BuildStudentProgress`/`InterventionsSummaryComposer` continuam corretos quando um aluno passa a ter N registos em vez de 1 — devem simplesmente listar/contar mais linhas (é mais verdadeiro), não é preciso lógica nova, mas confirma com um teste que nenhuma soma/contagem parte disto.

**Vue (`resources/js/pages/interventions/Show.vue`):**
- Campo hoje rotulado "Intervenção" (linha ~754, `<select v-model="form.intervention_type">`) passa a multi-select pesquisável com chips (ou, se decidires que o padrão do projeto não tem componente de combobox reutilizável, um padrão de checkboxes agrupadas pelos `typeGroups` já existentes — decide pelo que for mais coerente com o resto da app; audita se já existe algum componente multi-select/combobox reutilizável em `resources/js/components/` antes de construir um novo).
- Mostrar todas as escolhidas como chips removíveis, pesquisa por texto, sem duplicados, ordem estável (ordem de seleção).
- Rótulo passa a **"Medidas pedagógicas"** (plural — ver secção de nomenclatura abaixo). Ajusta o texto de ajuda/placeholder para deixar claro que pode escolher mais do que uma.
- Depois de gravar, a lista de intervenções deve indicar visualmente quais foram criadas no mesmo lote (`created_batch_ulid` partilhado) — um agrupamento visual leve (ex.: uma etiqueta "Registadas em conjunto" ou agrupamento visual), sem esconder que são N registos independentes.

### B) "Enquadramento pedagógico/legal" — várias combinações nível+medida por registo

Nova tabela filha/pivot, ex. `intervention_support_measures`:

```php
Schema::create('intervention_support_measures', function (Blueprint $table) {
    $table->id();
    $table->ulid('ulid')->unique();
    $table->foreignId('intervention_id')->constrained()->cascadeOnDelete();
    $table->string('support_measure_level', 32); // CHECK universal|selective|additional em MySQL
    $table->string('support_measure_code', 64);  // CHECK contra os valores do enum em MySQL
    $table->string('legal_mapping_source', 32)->nullable();
    $table->timestamps();
    $table->unique(['intervention_id', 'support_measure_level', 'support_measure_code'], 'intervention_support_measure_unique');
});
```

Usa `addCheck()` (ver padrão nas migrations existentes de `interventions`) para os CHECKs em MySQL, guardado por `getDriverName()`, e cuidado com a ordem no `down()` do rollback (não largar o índice único antes da FK que o usa — armadilha já documentada em `docs/workflow.md`).

**Migração de dados existentes (OBRIGATÓRIO, na mesma migration ou numa seguinte):**
Para cada `Intervention` com `support_measure_level` E `support_measure_code` ambos preenchidos, cria uma linha em `intervention_support_measures` com esses valores e o `legal_mapping_source` atual. Zero perda, zero duplicação. As colunas antigas (`support_measure_level`, `support_measure_code`, `legal_mapping_source`) **mantêm-se na tabela `interventions`** (não as elimines nesta fatia — evita quebrar leituras existentes de backup/export enquanto não se confirma que nada mais as lê; podes marcá-las como "legado, ler só para migração" num comentário, mas remover é fora do âmbito). O registo migrado tem de aparecer, depois da migration, exatamente como antes (um par visível), apenas agora editável para adicionar mais.

**Model `Intervention`:** nova relação `supportMeasures()` (`hasMany(InterventionSupportMeasure::class)`). Continua a expor um acessor de conveniência para o primeiro/legado se algum código ainda precisar (mas prefere migrar esse código para a relação nova).

**Controller:** `store()`/`update()` passam a aceitar `support_measures` como array de `{level, code}` (substituindo os campos singulares `support_measure_level`/`support_measure_code` nesse bloco — mantém `evaluation_adaptation_code` como está, é um campo à parte). Validação:
- Cada entrada: `level` obrigatório (enum), `code` obrigatório (enum).
- Duplicado exato (mesmo par level+code) no mesmo payload → rejeitado.
- Se o mesmo código legalmente só puder existir num nível (confere no catálogo real, `SupportMeasureCode`/`PortugalInclusiveEducationFramework` — **não inventes essa regra se não existir hoje**), valida contra isso; caso contrário não bloqueies combinações.
- Guarda atomicamente: `sync`-like (substitui o conjunto completo em cada update, numa transação).

**Vue:** bloco "Enquadramento pedagógico/legal" (linhas ~1011-1076 de `Show.vue`) passa de dois `<select>` singulares para uma lista de linhas `[Nível da medida] [Medida] [Remover]` + botão "Adicionar medida de suporte". Mostrar visualmente agrupado por nível se isso não complicar a edição (arquiteto: agrupar só melhora se não atrapalhar remover/editar uma linha específica).

**Nota do architect a reconfirmar durante a implementação:** com (A) a criar um registo por tipo pedagógico, o exemplo de "3 medidas em 3 níveis" do pedido original tende a mapear 1:1 para 3 registos distintos (cada um com o seu próprio par nível+medida automático). O pivot de (B) continua a ser necessário para os casos em que um professor quer, manualmente, mais do que um par nível+medida no MESMO registo (ex.: tipos com mapeamento `contextual`, tipo "Outro", ou correção manual). Implementa (B) na mesma — é pedido explícito do produto com testes próprios — mas não é preciso otimizar a UI para o caso "N pares = N tipos", porque esse caso já fica coberto por (A) criando N registos separados.

## NOMENCLATURA — renomear sem confundir

**Não uses "Medidas" sozinho em lado nenhum.** Os dois blocos usam o mesmo vocabulário-base (`SupportMeasureCode` e vários casos de `InterventionType` têm o mesmo texto, ex. "Diferenciação pedagógica" existe nos dois catálogos) — um "Medidas" sem qualificativo nos dois sítios do mesmo formulário recriaria a confusão que o pedido explicitamente quer evitar.

- Campo hoje "Intervenção" → **"Medidas pedagógicas"**.
- Bloco "Medida de suporte à aprendizagem" → **"Medidas de suporte à aprendizagem"** (plural) ou, onde o rótulo precisar de ser mais compacto, **"Medida de suporte (enquadramento legal)"**.
- Auditar (grep) todas as ocorrências de "Intervenção"/"intervention" na UI antes de renomear — só mudar onde o campo denota o catálogo `InterventionType` que está a passar a plural. Não mexer em: nome do módulo "Estratégias e Medidas" (já está correto), nome de rotas, nome de tabelas/colunas/classes PHP, valores dos enums (§17 — mudar o código reescreve o significado do que já foi gravado), `displayTitle()` (fallback `__('Intervenção')` — pode manter-se, é só um fallback de texto quando não há tipo nem estratégia, não precisa mudar nesta fatia mas confirma que não fica confuso ao lado do resto), e nunca reescrever `summary` já gravado em `audit_events` (só entradas novas usam o texto novo).

## Backward compatibility

- Registos antigos (1 tipo, 1 par nível+medida) continuam a abrir e a mostrar-se exatamente como hoje.
- Filtros existentes por `intervention_type` e por `support_measure_level` continuam a funcionar (ligar à relação nova onde for preciso, ex. `whereHas('supportMeasures', ...)`).
- Exports/backup (`BuildPedagogicalRecordsPlan`, `GenerateDataExport`, `ValidateBackupPayload`) — adiciona os novos dados (measures do lote via `created_batch_ulid`, pares via `supportMeasures()`) sem quebrar o formato existente; se o esquema de backup tiver número de versão, incrementa-o.
- `InterventionsSummaryComposer` e outros composers de relatório: onde hoje mostravam um único par/tipo, passam a mostrar todos (lista ou separado por `·`), nunca truncar silenciosamente.

## Testes obrigatórios

Segue à letra as secções 17, 18 e 19 do pedido original do produto (ficheiro de instruções da tarefa) — não preciso de as repetir aqui, mas confirma que cada alínea A–I (secção 17) e A–H (secção 18) tem um teste correspondente, mais o teste de migration da secção 19 (migrate:fresh, migrate, dados legados simulados, rollback limpo, migrar de novo — cuidado com MySQL 1553 em FKs/índices únicos no rollback, já mencionado acima).

## Gates

`composer ci:check` (Pint, Larastan nível 7, PHPUnit) + `npm run lint`/`vue-tsc`/Vitest/build conforme o `composer ci:check`/scripts do projeto. Se o PHP local estiver bloqueado (Device Guard/WDAC), não contornes — sinaliza e usa CI real (abre PR de rascunho se for preciso só para correr CI; não integres em main). Se a migration tiver CHECK constraints, corre também `migrate:fresh`/`migrate:rollback` contra MySQL real (:3308) — SQLite não valida CHECK.

## Versão e changelog

Versão atual em `config/app.php`: `0.140.4`. Esta fatia muda schema e semântica de multiplicidade → **MINOR**: propõe `0.141.0`. Atualiza `config/app.php` e `CHANGELOG.md` (formato Keep a Changelog, PT-PT, como as entradas existentes).

## Não tocar

Turmas de apoio, lifecycle de turmas, grupos T1/T2, horários, avaliação/classificações, IA/Gemini, Inovar, Teams, PlanVersions, planos, landing, assiduidade, extração IA de estratégias (o `InterventionStrategySuggester` pode continuar a sugerir um tipo só de cada vez — não precisa de sugerir vários), `evaluation_adaptation_code` (fica singular, fora do âmbito).

## Entrega

Não integres em `main`, não faças deploy. Quando terminares (gates verdes, testes novos passam, QA funcional feito no browser), para e resume o trabalho: ficheiros alterados, testes novos, resultado dos gates, versão/commit. Eu (Claude, orquestrador) escrevo o relatório final ao utilizador a partir do teu resumo — não precisas de formatar esse relatório, só de me dares factos verificáveis.
