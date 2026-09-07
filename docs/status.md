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
| **Evolução do Aluno** | ✅ | Vista longitudinal individual: resultado atual com a leitura nomeada, linha de momentos reais (períodos + intercalares lidas do snapshot), domínios, classificações, autoavaliação, registos e intervenções. Consome `BuildResultsProgression` e `BuildClassStatistics` — não calcula nada, e constrói a progressão **uma só vez**, passando-a à camada estatística. Base, sem paywall interno. |
| **Auditoria (§22.4)** | ✅ | `audit_events` imutável, tenant-scoped; página «Registo de atividade». |
| **Registos / Evidências (§14)** | ✅ | Diário de bordo qualitativo; nunca no cálculo. |
| **Autoavaliação (§15)** | ✅ | Por domínio, comparada com o cálculo, nunca somada. |
| **Intervenções (§14)** | ✅ | O raciocínio pedagógico do professor: **porquê** (situação/dificuldade), **o quê** (estratégia), **para quê** (objetivo) e **o que se observou depois** (acompanhamentos datados, com avaliação do efeito pelo professor). Âmbito individual/grupo/turma, «rever em» e revisão pendente. Reutiliza a biblioteca `report_library_entries` dos Relatórios — dificuldade → estratégia → objetivo — e guarda cópia do texto escolhido, nunca uma FK. Nada é inferido: um resultado que sobe não avalia nada. |
| **Backoffice de plataforma** | ✅ | `/admin` super-admin: gestão de contas, criar/provisionar, SMTP na BD (sobrepõe `.env`), acesso técnico (impersonar, gated por `is_support_technician` — capability distinta de `is_platform_admin`, autorizada por defeito a admins internos e revogável individualmente). Guia: [backoffice.md](backoffice.md). Ver [ADR-0014](adr/0014-technical-support-access.md). |
| **Organizações — Fatia 4** | ✅ | Sair/remover membros, transferir responsabilidade e reatribuir turmas órfãs como estado derivado; isolamento tenant, auditoria e autoria histórica preservados. Exportação pessoal ("os meus dados") em ZIP, síncrona, expira em 24h; arquitetura de política de retenção (`config/retention.php`) documentada, sem purga automática. Ver [membership-lifecycle.md](membership-lifecycle.md) e [data-lifecycle.md](data-lifecycle.md). |
| **Encerramento de conta/organização — Fatia 5** | ✅ | Pedido/cancelamento recuperável (60/90 dias) para conta pessoal e organização institucional; bloqueio de escrita durante a janela; guarda de ownership institucional; pré-visualização de elegibilidade só de leitura (`retention:status`); sem purga automática nem anonimização. Estrutura do Ano Letivo (Anos letivos + Disciplinas) centralizada em Configuração — mesmas rotas/policies, só descoberta. Ver [account-closure.md](account-closure.md) e [academic-structure.md](academic-structure.md). |
| **Aulas e Sumários — Fatia 3 de 6** | 🚧 | `/lessons` mantém a vista semanal operacional e cada aula passa a ter o Sumário como único centro de conteúdo: notas privadas do professor, recursos e TPC opcionais no mesmo registo. Preparado é derivado ao guardar conteúdo; Lecionado continua uma ação explícita e separada. `LessonPlan` permanece como infraestrutura dormente, sem UI nem endpoint de escrita. Ainda sem cumprimento por aluno, sequências, continuidade ou faltas/atrasos — ver desenho e plano faseado em [docs/superpowers/specs/2026-08-23-lessons-summaries-planning-sequences-design.md](superpowers/specs/2026-08-23-lessons-summaries-planning-sequences-design.md) e [docs/superpowers/plans/2026-08-23-lessons-summaries-planning-sequences.md](superpowers/plans/2026-08-23-lessons-summaries-planning-sequences.md). |
| **Central de Suporte** | ✅ | Pedidos de professores, com e sem conta. Quatro estados (`open`/`in_progress`/`waiting_for_user`/`resolved`), conversa canónica no Lapispro e email só como aviso — sem processamento de email de entrada. Guest cria mas não consulta; um professor vê só o que abriu, e nem a organização nem o admin institucional dão acesso. Retenção 23d/30d/24 meses com anonimização verdadeira e suspensão classificada. Não é capability nem entitlement: existe igual no Base, Pro e Institucional. Ver [ADR-0011](adr/0011-support-centre.md). |
| **Backoffice comercial — Frente B** | ✅ | `Admin > Comercial`: contas, subscrições, condição comercial e receita real, só para superadmin. Separa **plano** (o que a conta usa) de **condição comercial** (em que termos lá chegou) de **pagamento** (dinheiro que entrou). «Membro Fundador» é condição do Pro, nunca um plano — `Entitlements` não lê a coluna. Receita = `SUM(amount_cents) WHERE status = 'paid'`, agrupada por `paid_at`; nunca `nº de Pro × preço`. Registo manual de pagamentos recebidos (não há gateway), com correcção por reembolso total ou anulação — sempre com motivo e autoria, nunca por edição. **Nenhum histórico foi inventado**: todas as subscrições existentes ficam «Origem não registada». Vouchers: motor real (emissão, validação, resgate com reserva/confirmação; três famílias V1) — o texto legado em pagamentos manuais continua não convertido. Ver [backoffice.md](backoffice.md). |
| **Capacidades temporárias** | ✅ | Presets internos, códigos resgatáveis e atribuição direta convergem em grants temporários auditáveis. Não alteram plano, versão ou subscrição; expiração/revogação devolve a resposta normal do resolver e um override `enabled=false` continua a vencer. Ver [ADR-0015](adr/0015-temporary-capability-grants.md). |
| **Pauta de Avaliação — «Preparar fecho»** | ✅ | Checklist de preparação dentro da própria pauta (`EvaluationSheetReadiness`): decisões vs. propostas, cobertura e domínios sem resultados (flags do motor, nada recalculado), elementos em revisão, autoavaliações quando a turma as usa, última exportação Inovar como informação. Três estados (ok/atenção/não aplicável), lista só de alunos com pendências, links de resolução. «A decorrer vs. fecho» deriva de `InovarLevelOption::includedByDefault` — nunca do nome do período. **Nada bloqueia o fecho** (§3.3). |
| **Pauta de Avaliação — centro de decisão** | ✅ | A pauta passa a ser onde o professor **atribui e altera** a classificação: «Atribuir» onde não há decisão, o próprio valor como «Alterar» onde há, e um painel com a proposta, o global, os domínios e a autoavaliação lado a lado. **Sem segunda fonte de verdade** — escreve por `classifications.decide`, a mesma linha, o mesmo `ConfirmClassification` com o seu bloqueio, o mesmo rasto de auditoria. A decisão da pauta **atual** é sempre reeditável: guardar um momento, exportar ou ter histórico não a fecham. A **autoavaliação** viaja no read model (juízo global e por domínio; leitura canónica em `SelfAssessmentReading`, partilhada com Resultados) e por isso fica congelada no que for guardado — e nunca entra no cálculo (§15). Cada momento guardado exporta em **CSV e Excel** gerados só do payload congelado; a pauta atual tem os seus. Ver [evaluation-sheet-exports.md](evaluation-sheet-exports.md). **Teams direto: não implementado**, por ausência total de infraestrutura Microsoft — o ponto de extensão está documentado. |
| **Pauta de Avaliação — decisão por domínio, momentos e Inovar** | ✅ | A apreciação de **cada domínio** passa a ser uma decisão do professor, ao lado da proposta e nunca por cima dela: `domain_appreciation_decisions` guarda só o nível decidido — 47,5 % continua a ser 47,5 %, «2» continua a ser a proposta, «3» é a decisão —, apagar a linha é voltar à proposta, e é sempre reeditável. Não entra em cálculo nenhum. Desligar «Valores quantitativos» deixa de mostrar códigos: as células passam a escrever a **menção da escala** («Bom»), com o código no texto acessível. A cobertura ganha **três estados numa só fonte** (`CoverageWording`) e a frase «Embora tenha havido avaliação…» só é dita onde há resultado. O topo mostra os **momentos estruturais** de cada unidade temporal — intercalar antes do respetivo final —, com rótulos derivados da configuração; uma fotografia guardada continua a viver só no Histórico. A correspondência da grelha do **Inovar** passa a ser por confiança (forte / provável / ambígua / sem correspondência): o N.º de processo deixa de ser requisito, nomes do meio e acentos deixam de estragar uma correspondência óbvia, «Martins» nunca corresponde a «Martin», e nenhuma grelha sai com uma linha por identificar. O valor exportado continua a ser o `inovar_code`. Ver [evaluation-sheet-decisions.md](evaluation-sheet-decisions.md) e [evaluation-sheet-exports.md](evaluation-sheet-exports.md). |

| **Quadro Síntese ao longo do ano · avaliação contínua** | ✅ | O Quadro Síntese ganha uma segunda vista — o ano lido pelos **momentos** e não só pelos números —, sem terceira grelha: `BuildClassSynopsis` agrega fontes canónicas (a pauta de cada unidade, as fotografias guardadas, os elementos, a média contínua) e **não criou tabela nenhuma**. Três níveis expansíveis (momentos → domínios → elementos), filtro por aluno e ligação ao Relatório do aluno. A **avaliação contínua** passa a ser regra fechada: média dos resultados FORMAIS de cada unidade temporal, com os pesos de `period_weight_percent` quando existem — as **intercalares são fotografias e não entram**. Não substitui o «acumulado» do motor, que fica como estava; os dois aparecem lado a lado (89,73 % e 89,40 % no cenário de demonstração). Exportação **Excel de quatro folhas** (quadro, domínios, elementos, configuração). Ver [domain-model.md §6.4–6.7](domain-model.md). |

Suite: 1986 testes verdes (1 skipped) · Pint/Larastan/vue-tsc limpos. **Em produção** em
[lapispro.com](https://lapispro.com) — `lapis.criativatek.com` responde 301 para lá.

## Regras pedagógicas (5 questões que bloqueavam a Fase 1)

Q1 (bandas de escala), Q2 (ausências), Q3 (arredondamento), Q4 (acumulado) —
**decididas e implementadas**.

> **Q4 tem agora duas leituras a coexistir.** A **avaliação contínua** — média dos
> resultados formais de cada unidade, com os pesos configurados — é regra fechada e
> está implementada (`ContinuousAssessment`). O **acumulado** do motor continua a
> reprocessar os elementos brutos do ano e não foi alterado. Falta decidir se
> «acumulado» deve passar a significar a avaliação contínua ou se o produto mantém
> as duas leituras com nomes distintos; enquanto não houver decisão, o motor não
> muda. Ver [domain-model.md §6.4](domain-model.md) e a Q4 da mesma ficha.

**Q5 — import do Intuitivo: BLOQUEADO.** Precisa de um ficheiro real anonimizado de
exportação antes de escrever o parser. O prompt-base diz CSV/XLSX; o mockup mostra
`7A_Avaliacao_2P_2026.xml`. São camadas de parsing diferentes — não inventar o
formato. Tabelas de suporte (`import_jobs`) desenhadas em `domain-model.md`.

## O que falta

- **Resultados persistidos (§6.2):** `student_overall_results` / `student_domain_results`
  / `instrument_student_results`. Continua tudo calculado on-the-fly. A dupla
  passagem agregada da Evolução do Aluno **está resolvida**:
  `BuildClassStatistics::for()` aceita uma progressão já construída, e a página
  passou de 121 para 72 queries na turma de demonstração (Estatística: 67). O que
  falta é o passo maior — persistir resultados, para que nem a primeira passagem
  seja precisa.
- **Páginas ainda placeholder:** nenhuma. «Alunos» foi a última, e passou a ser um
  diretório real (`StudentDirectoryController`, `resources/js/pages/students/Index.vue`):
  encontrar, filtrar e navegar para o Acompanhamento ou para a Turma. A ficha
  pedagógica continua em «Acompanhamento → Aluno» e a gestão administrativa da
  inscrição continua em «Turmas» — o diretório não duplica nenhuma das duas. A
  maquinaria do placeholder (`PlaceholderController`, `resources/js/pages/Placeholder.vue`,
  o ciclo em `routes/app.php`) fica no sítio para a próxima entrada que precise dela.
- **Fluxos avulsos:** encerramento de período (§13.6 — o trigger `period_closed` existe
  no enum, falta o fluxo), anulação de instrumento.
- **Deploy:** ver [deployment.md](deployment.md) (CloudPanel · lapis.criativatek.com).

## Próximo candidato sem bloqueio, por valor

1. **Q5 Intuitivo** — assim que houver o ficheiro real.

*(«Alunos», antes o primeiro desta lista, está feito: o diretório fechou o último
placeholder da Fase 1.)*

*(A Análise da Turma, antes listada aqui como placeholder, está feita desde a
[0.35.0] — "Estatística": [`ClassStatisticsController`](../app/Http/Controllers/ClassStatisticsController.php),
[`BuildClassStatistics`](../app/Services/Assessment/BuildClassStatistics.php),
`resources/js/pages/results/Statistics.vue`. Esta secção não tinha sido
atualizada depois desse trabalho.)*

## Dívida técnica registada

Coisas conhecidas, deliberadamente **não** corrigidas na release em que foram
descobertas, para não misturar frentes. Cada uma precisa da sua própria fatia.

- **`Report::isIntact()` dá falsos negativos.** O hash de integridade documental
  é `sha256(json_encode($document))`, calculado sobre o array em memória no
  momento da finalização. O MySQL guarda a coluna como `JSON` e **normaliza a
  ordem das chaves** ao gravar, pelo que o JSON que volta da base de dados já
  não é a mesma cadeia de bytes que foi assinada. Resultado: `isIntact()`
  devolve `false` para **todos** os relatórios finalizados, incluindo os que
  nunca foram tocados — confirmado nos seis relatórios da base local, três dos
  quais nenhuma ferramenta alterou. Não é corrupção de dados: é uma verificação
  que não pode passar.

  Descoberto ao trabalhar a composição documental dos relatórios e
  deliberadamente deixado fora dessa fatia: mexer no esquema de hash de
  documentos assinados é uma decisão por si só, e a alternativa óbvia —
  serialização canónica (chaves ordenadas, `JSON_UNESCAPED_*` fixos) — invalida
  todos os hashes já gravados e obriga a decidir o que fazer com eles.
  Enquanto não for tratado, **`isIntact()` não serve para detetar adulteração** e
  não deve ser usado como se servisse.
