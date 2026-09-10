# Measures Multi-select Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir criar várias intervenções independentes num lote e associar vários pares de enquadramento pedagógico/legal a cada intervenção.

**Architecture:** A criação aceita `intervention_types` e uma Action cria um registo por tipo numa única transação, marcando-os com o mesmo `created_batch_ulid`. Uma tabela filha guarda os pares legais canónicos; as colunas singulares existentes permanecem e espelham o primeiro par por compatibilidade. A eliminação de lote resolve e elimina cada registo individualmente com autorização e auditoria.

**Tech Stack:** Laravel 13/PHP 8.4, Eloquent/MySQL, PHPUnit 12, Vue 3.5/TypeScript/Inertia 3, Tailwind 4, Vitest.

## Global Constraints

- Trabalhar apenas em `D:\HERD\lapis-measures`, branch `feat/measures-multi-select`.
- Não integrar em `main`, não fazer push e não fazer deploy.
- Tenant no container; nunca remover global scopes para contornar tenancy.
- Código/BD em inglês e UI em pt-PT; ULID em URLs; `#[Fillable]` explícito.
- `created_batch_ulid` é apenas proveniência e nunca propaga estado, conclusão ou enquadramento.
- A criação aceita 1–10 tipos distintos; edição continua singular.
- `evaluation_adaptation_code` permanece singular e fora da tabela filha.
- Migrations reversíveis e constraints reais verificadas em MySQL `:3308`, se PHP/ambiente permitirem.

---

### Task 1: Persistência e migração dos pares legais

**Files:**
- Create: `database/migrations/2026_09_10_000100_add_batches_and_support_measures_to_interventions.php`
- Create: `app/Models/InterventionSupportMeasure.php`
- Modify: `app/Models/Intervention.php`
- Test: `tests/Feature/Interventions/InterventionSupportMeasureMigrationTest.php`

**Interfaces:**
- Produces: `Intervention::supportMeasures(): HasMany`, `created_batch_ulid`, modelo filho com ULID e casts enum.

- [ ] Escrever teste que simula par legado, executa a migration e prova backfill sem duplicação/perda.
- [ ] Criar migration aditiva com coluna/index, tabela filha, CHECKs MySQL, unique composto e backfill por chunks.
- [ ] Implementar `down()` que remove primeiro a tabela filha e depois índice/coluna do lote.
- [ ] Criar modelo filho com `#[Fillable]`, `HasUlids`, casts e relação inversa.
- [ ] Atualizar `Intervention` com fillable, docblock inegociável e relação.
- [ ] Correr os testes focados e ciclos migrate/rollback/migrate em SQLite e MySQL real.

### Task 2: Contratos HTTP e casos de uso transacionais

**Files:**
- Create: `app/Http/Requests/StoreInterventionRequest.php`
- Create: `app/Http/Requests/UpdateInterventionRequest.php`
- Create: `app/Actions/Interventions/CreateInterventionBatch.php`
- Create: `app/Actions/Interventions/SyncInterventionSupportMeasures.php`
- Modify: `app/Http/Controllers/InterventionController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Interventions/InterventionBatchTest.php`
- Test: `tests/Feature/Interventions/InterventionSupportMeasureTest.php`

**Interfaces:**
- Consumes: `supportMeasures()`, `created_batch_ulid`.
- Produces: criação `intervention_types: list<string>`, edição `intervention_type: string`, `support_measures: list<{level:string,code:string}>`, endpoint DELETE de lote por ULID.

- [ ] Escrever testes vermelhos para 1–10 tipos distintos, `Other`, proibição manual em lote, atomicidade, participantes e auditoria por linha.
- [ ] Implementar Form Requests, incluindo erro por pares duplicados e correspondência código/nível segundo o enum real.
- [ ] Implementar sincronização total dos pares e espelho do primeiro nas colunas legadas.
- [ ] Implementar criação transacional de N linhas com enquadramento automático resolvido individualmente.
- [ ] Expor os dados de lote/pares no payload Inertia e filtrar nível via relação nova.
- [ ] Escrever e implementar remoção de lote autorizada, linha a linha, com soft-delete e auditoria.
- [ ] Provar por testes que editar/remover um par ou registo não altera os restantes.

### Task 3: Compatibilidade de exports e relatórios

**Files:**
- Modify: `app/Actions/DataExports/GenerateDataExport.php`
- Modify: `app/Services/Import/Backup/ValidateBackupPayload.php`
- Modify: `app/Services/Import/Backup/BuildPedagogicalRecordsPlan.php`
- Modify: `app/Actions/DataImports/WritePedagogicalRecords.php`
- Modify: `app/Services/Reporting/Source/StudentReportSource.php`
- Modify: `app/Services/Reporting/Source/ClassReportSource.php`
- Modify: `app/Services/Reporting/Sections/InterventionsSummaryComposer.php`
- Test: `tests/Feature/DataExports/DataExportTest.php`
- Test: `tests/Feature/Reports/StudentReportTest.php`
- Test: `tests/Feature/Reports/ClassReportSourceTest.php`

**Interfaces:**
- Produces: backup com `created_batch_ulid` e `support_measures`; relatórios que contam N intervenções e apresentam todos os pares.

- [ ] Adicionar testes de export/import round-trip com lote e vários pares.
- [ ] Atualizar eager loads, serialização, validação e escrita sem quebrar campos legados.
- [ ] Adicionar teste de relatório/progresso que prova contagem/listagem independente das linhas do lote.
- [ ] Atualizar fontes/composer para não truncarem pares legais.

### Task 4: Interface Vue

**Files:**
- Modify: `resources/js/pages/interventions/Show.vue`
- Create: `resources/js/components/interventions/InterventionTypeMultiSelect.vue`
- Test: `resources/js/components/interventions/InterventionTypeMultiSelect.test.ts`

**Interfaces:**
- Consumes: `created_batch_ulid`, `created_batch_size`, `support_measures` e endpoints HTTP.
- Produces: pesquisa, chips removíveis em ordem de seleção, linhas legais adicionar/remover e ação de remover lote.

- [ ] Criar teste Vitest do multi-select para pesquisa, seleção estável, remoção e ausência de duplicados.
- [ ] Implementar componente acessível reutilizando controlos visuais existentes.
- [ ] Adaptar o formulário: plural apenas na criação e select singular na edição.
- [ ] Implementar múltiplas linhas legais, mantendo adaptação de avaliação singular.
- [ ] Mostrar agrupamento leve de lote e confirmação/ação de remoção integral.
- [ ] Garantir loading, erros por índice e layout utilizável a ~390 px.

### Task 5: Verificação, documentação e entrega

**Files:**
- Modify: `config/app.php`
- Modify: `CHANGELOG.md`
- Modify: `docs/status.md` se o estado funcional exigir registo.

- [ ] Rever a policy existente e provar que as rotas novas herdam autorização/tenancy corretas.
- [ ] Correr testes focados, `composer ci:check`, `npm run lint:check`, `npm run types:check`, `npm run test:unit` e `npm run build` em série.
- [ ] Fazer QA no browser com o login demo: criar 2–3 tipos e 2 pares, reabrir, remover isoladamente, remover lote; repetir inspeção a ~390 px e verificar consola.
- [ ] Atualizar versão para `0.141.0` e changelog Keep a Changelog em pt-PT.
- [ ] Rever integralmente diff/status e ausência de segredos ou alterações fora do worktree.
- [ ] Criar commit convencional coeso sem push/deploy/integração.
