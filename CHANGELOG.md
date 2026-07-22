# Changelog — LÁPIS

Formato: [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/). Versão semântica pré-1.0 enquanto as fases são construídas.

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
