# Estado do projeto — onde pegar

Instantâneo do que está feito, o que está bloqueado e o que falta. Detalhe por
versão em [CHANGELOG.md](../CHANGELOG.md); o "porquê" das decisões em [docs/adr/](adr/).

## Feito (Fase 0 → Fase 3, versões 0.1 → 0.14)

| Área | Estado | Notas |
|---|---|---|
| **Fundação** | ✅ | Tenancy no container (ADR-0002), entitlements em tabelas, shell + navegação, auth Fortify (2FA + passkeys), CI em MySQL. |
| **Anos letivos / períodos** | ✅ | Base temporal (§9). |
| **Disciplinas** | ✅ | |
| **Perfis de avaliação** | ✅ | Domínios/pesos, escalas, **versionamento** (ativação congela a versão). |
| **Turmas / alunos / inscrições** | ✅ | Alunos cifrados (identidade separada), pseudónimo para processamento. |
| **Instrumentos + grelha** | ✅ | |
| **Motor de cálculo** | ✅ | Determinístico; `ClassResultsCalculator` (período + acumulado, §6.3). |
| **Decisão de classificação (§7)** | ✅ | Propor → confirmar → override (com motivo) → **publicar**; `calculation_snapshots` imutáveis (hash SHA-256). |
| **Resultado acumulado (§6.3, Q4)** | ✅ | Reprocessa elementos brutos do ano, não média de médias. |
| **Migração de perfil (§10.2, A4)** | ✅ | Pré-visualização antes/depois + motivo + registo imutável; histórico não recalcula. |
| **Painel do Professor** | ✅ | Pendências por confirmar/publicar por turma. |
| **Relatórios — pauta** | ✅ | Só notas decididas; imprimível + CSV. Vive agora em `pautas.*`, dentro do módulo Relatórios. |
| **Relatórios — documentos** | ✅ | Turma, individual, por registos e de escola. Lê os read models canónicos (uma chamada), escreve frases em pt-PT, o professor edita, finaliza (congela texto + números + identidade + logótipo) e exporta PDF/DOCX da mesma estrutura. Base descreve; `report_pedagogical_analysis` (Pro) interpreta. Ver [ADR-0005](adr/0005-reports-are-documents-not-downloads.md). |
| **Relatórios — modelos** | ✅ | `report_templates` (sistema / pessoal / escola). Um modelo guarda estrutura, ordem, registo e opções — nunca dados. O relatório guarda um snapshot do modelo: editar o modelo não altera relatórios existentes. Pessoais exigem `template_sharing` (Pro), institucionais `institution_library` (Institucional). |
| **Relatórios — reordenar secções** | ✅ | Rascunho apenas. Rato e teclado, com anúncio aria-live; só `position` muda. A ordem sobrevive a regenerar/restaurar/excluir e chega à pré-visualização, ao PDF, ao DOCX e ao documento congelado. |
| **Relatórios — aperfeiçoar redação** | ✅ (desligado) | Camada de IA que **reescreve** uma secção; nunca é fonte de facto. Números e datas saem como marcadores, nomes saem como «Aluno A», e um guarda recusa o que voltar alterado ou inventado. `ai_assistance` (Pro). **Sem fornecedor escolhido**: `LAPIS_AI_DRIVER` vazio = indisponível, e o módulo funciona na mesma. Ver [ADR-0006](adr/0006-ai-rewrites-text-it-is-never-the-source.md). |
| **Auditoria (§22.4)** | ✅ | `audit_events` imutável, tenant-scoped; página «Registo de atividade». |
| **Registos / Evidências (§14)** | ✅ | Diário de bordo qualitativo; nunca no cálculo. |
| **Autoavaliação (§15)** | ✅ | Por domínio, comparada com o cálculo, nunca somada. |
| **Intervenções (§14)** | ✅ | Ciclo de vida + apreciações de eficácia. |
| **Backoffice de plataforma** | ✅ | `/admin` super-admin: gestão de contas, criar/provisionar, SMTP na BD (sobrepõe `.env`), impersonar. Guia: [backoffice.md](backoffice.md). |

Suite: 1890 testes verdes (1 skipped) · Pint/Larastan/vue-tsc limpos. **Em produção** em
[lapis.criativatek.com](https://lapis.criativatek.com) (versão 0.19.x).

## Regras pedagógicas (5 questões que bloqueavam a Fase 1)

Q1 (bandas de escala), Q2 (ausências), Q3 (arredondamento), Q4 (acumulado) —
**decididas e implementadas**.

**Q5 — import do Intuitivo: BLOQUEADO.** Precisa de um ficheiro real anonimizado de
exportação antes de escrever o parser. O prompt-base diz CSV/XLSX; o mockup mostra
`7A_Avaliacao_2P_2026.xml`. São camadas de parsing diferentes — não inventar o
formato. Tabelas de suporte (`import_jobs`) desenhadas em `domain-model.md`.

## O que falta

- **Fase 3 restante:** Evolução do Aluno, Análise da Turma (distribuição/estatística).
- **Resultados persistidos (§6.2):** `student_overall_results` / `student_domain_results`
  / `instrument_student_results`. Hoje tudo é calculado on-the-fly; as vistas de
  análise/evolução vão precisar de persistência. (Deixado por YAGNI até existirem essas vistas.)
- **Páginas ainda placeholder:** Alunos (gestão), Avaliações (workspace).
- **Fluxos avulsos:** encerramento de período (§13.6 — o trigger `period_closed` existe
  no enum, falta o fluxo), anulação de instrumento.
- **Deploy:** ver [deployment.md](deployment.md) (CloudPanel · lapis.criativatek.com).

## Próximo candidato sem bloqueio, por valor

1. **Análise da Turma** ou **Evolução do Aluno** — precisam primeiro de decidir a
   persistência de resultados (§6.2) ou calcular on-the-fly como o resto.
2. **Alunos** (página de gestão) — fecha um placeholder da Fase 1.
3. **Q5 Intuitivo** — assim que houver o ficheiro real.
