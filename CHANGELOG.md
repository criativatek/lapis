# Changelog — LÁPIS

Formato: [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/). Versão semântica pré-1.0 enquanto as fases são construídas.

## [0.11.0] — 2026-07-23

### Adicionado

- **Trilha de auditoria (§22.4).** Tabela `audit_events` imutável e tenant-scoped: os eventos que tocam o registo de um aluno deixam uma linha com o autor, o momento e o contexto (valores, motivo). Emitidos pelos serviços que detêm cada ação — confirmação e alteração de classificação (com o motivo), publicação (evento único em lote), migração de perfil, ativação de versão, exportação de pauta (§22.5). Página «Registo de atividade» só-leitura.
- Decisão: tabela própria em vez de `spatie/laravel-activitylog` — o requisito é isolamento por organização + eventos de domínio (não diff de modelos), e uma tabela focada encaixa exato sem dependência nova.

## [0.10.0] — 2026-07-23

### Adicionado

- **Relatórios — pauta de classificações.** Por turma, a pauta dos alunos × períodos com as classificações **decididas** (confirmadas ou publicadas); uma proposta nunca aparece como nota. Imprimível (só a pauta, sem o shell) e exportável em CSV (com BOM UTF-8 para os acentos abrirem bem no Excel). Item «Relatórios» do menu passa a construído.

## [0.9.0] — 2026-07-22

### Adicionado

- **Painel do Professor.** Substitui o placeholder do starter kit por um painel real: saudação, resumo (turmas, classificações por confirmar, por publicar) e cartões por turma com as pendências e atalhos para Classificações/Resultados. Só mostra trabalho já criado — não decide nada. Agregação numa única query (join a `enrollments`), scoped à turma e à organização.

## [0.8.1] — 2026-07-22

### Corrigido (revisão independente da publicação + migração)

- **Bypass de autorização na publicação (P0):** `PublishClassifications` filtrava por período+âmbito mas não por turma; como os períodos pertencem ao ano letivo, publicar uma turma publicava as classificações confirmadas de outra turma do mesmo ano (mesmo de outro professor). Passa a filtrar por `enrollment_id` da turma. Teste com duas turmas no mesmo período.
- **Pré-visualização da migração mentia (P0):** mostrava «antes→depois, alterado» para classificações confirmadas/publicadas que a migração deixa intactas. Agora cada célula tem estado — `mantida` (decisão congelada), `sem proposta`, ou `recalculada` (antes/depois) — e só as recalculáveis contam para os totais.
- **Migração criava propostas não mostradas:** o re-cálculo passa a `refreshOnly` — só atualiza propostas em aberto existentes, nunca cria novas que o professor não viu na pré-visualização.
- **Concorrência:** confirmação de migração e publicação correm agora sob `lockForUpdate` com re-verificação do estado dentro da transação (evita registos de auditoria contraditórios e escritas cegas).
- Contagens da pré-visualização, do registo e do toast agora coincidem (vêm todas do mesmo documento). Guard `under_review` restrito aos instrumentos que contam.

## [0.8.0] — 2026-07-22

### Adicionado

- **Migração de perfil auditável (§10.2, A4).** Uma turma com resultados só muda de versão de perfil através de uma migração registada: pré-visualização por aluno do valor antes/depois em cada período, confirmação com motivo obrigatório, e um registo `class_profile_migrations` imutável.
  - Ativar uma nova versão **não** migra as turmas automaticamente — elas continuam na versão que produziu os seus resultados até uma migração explícita.
  - Ao migrar, as propostas em aberto recalculam sob a nova versão; as classificações confirmadas ou publicadas mantêm-se na versão antiga (o histórico não é recalculado).
  - `ClassController::updateProfile` passa a encaminhar para a migração quando a turma já tem decisões, em vez de trocar a versão em silêncio.

### Corrigido

- `MigrateClassProfile`: a relação `profileVersion` em cache era usada após a troca da FK, recalculando sob a versão antiga. Passa a apontar para a nova versão antes de re-propor.

## [0.7.0] — 2026-07-22

### Adicionado

- **Publicação da classificação (§13.2).** Botão «Publicar confirmadas»: as classificações confirmadas passam a `published` (comunicadas). Publicar **não recalcula nada** — só muda o estado e `published_at`.
  - Um elemento `under_review` (reclamação pendente) **bloqueia** a publicação da classificação desse período (§5); a contagem de retidas é reportada ao professor.

## [0.6.0] — 2026-07-22

### Adicionado

- **Resultado acumulado (§6.3, Q4).** Toggle «Por período / Acumulado» nas classificações. Com `accumulated_mode = all_valid_year_elements`, o motor reprocessa os elementos brutos de todos os períodos contribuintes até ao período em causa — **não** a média das médias dos períodos (que sobreponderaria os primeiros elementos).
  - Período e acumulado são decisões distintas para o mesmo (aluno, período): coexistem na tabela por âmbito.
  - O ingresso tardio atravessa o acumulado corretamente: o aluno é avaliado só pelos elementos que o alcançam (Filipe: só o 2.º período; nunca um zero).
  - `ClassResultsCalculator::forScope/forAccumulated`; proposta e confirmação passam a ser scope-aware.
  - DemoDataSeeder ganha um instrumento no 2.º período para o acumulado ter conteúdo.

## [0.5.0] — 2026-07-22

### Adicionado

- **Decisão de classificação (§7).** O motor propõe, o professor confirma. Tabelas `classifications` e `calculation_snapshots`, serviços `ProposeClassifications` e `ConfirmClassification`, e a página por turma/período.
  - A proposta determinística nunca é sobrescrita; o valor final do professor vive ao lado dela.
  - Alterar a proposta exige um motivo — garantido por CHECK em MySQL (`<=>` null-safe) **e** ao nível do serviço (cenário A10). Verificado com CHECK real em MySQL.
  - A confirmação congela um `calculation_snapshot`: cópias literais dos inputs, versão das regras, explicação estruturada e `payload_hash` SHA-256. Nunca atualizado.
  - Alunos sem resultado calculável (ausência total, ingresso tardio) **não** recebem proposta — nunca um zero.
  - Uma só classificação viva por (aluno, período, âmbito) via coluna gerada `status_active_flag`.
- **Página inicial LÁPIS.** Substitui o ecrã do starter kit Laravel; marca navy + âmbar, tema claro/escuro.

### Corrigido

- CHECK do A10 no `domain-model.md` estava incompleto: rejeitava o próprio estado `proposed` (`final_value` nulo vs proposta preenchida). Corrigido para admitir "sem decisão final" com ambas as colunas `final_*` nulas.
