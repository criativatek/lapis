# Fatia 3 — Relatório «Aulas e Serviço Letivo»: matriz de testes

**Estado:** matriz de testes, para escrever **antes** da implementação. Depende do
domínio da Fatia 1 (`not_held`) e da reconciliação da Fatia 2.

## 0. Definições que os testes fixam

Por professor, turma e intervalo `[de, até]` (nunca além de hoje):

| Número | Definição |
|---|---|
| **Previstas** | Ocorrências gravadas no intervalo (já passadas), de todas as espécies. |
| **Lecionadas** | `outcome = taught` (ou o estado antigo `taught` sem resultado). |
| **Atividade da turma** | `class_external_activity`: conta como lecionada para o serviço, não como desenvolvimento da disciplina. |
| **Professor ausente** | `teacher_absent`. |
| **Não realizadas** | `not_held`, **por categoria agregada** (nunca a causa individual de quem não é titular). |
| **Por registar** | Passadas e sem resultado. |

Invariante: `Previstas = Contabilizadas + Ausente + Não realizadas + Por registar`, em que
`Contabilizadas = Lecionadas + Atividade da turma`.

«Cerca de 80 % das aulas dadas» (origem do pedido) = `Contabilizadas / (Previstas − Não realizadas por
causa da escola)`. **Decisão pendente de produto:** o denominador não é automático (§5).

## 1. Contagem (unitários sobre `ClassLessonRecord`/novo serviço)

| # | Caso | Esperado |
|---|---|---|
| C1 | Sem aulas | secção «sem aulas», nunca zeros inventados |
| C2 | Só lecionadas | previstas = lecionadas |
| C3 | Uma de cada espécie | cada total = 1; invariante verdadeira |
| C4 | Aula futura sem resultado | fora de todos os totais |
| C5 | Aula passada sem resultado | só em «por registar» |
| C6 | Estado antigo `taught` sem `outcome` | conta como lecionada |
| C7 | Intervalo parcial | só ocorrências do intervalo; `de` > `até` recusa |
| C8 | Turma com grupos T1/T2 | cada ocorrência de grupo conta uma vez; a lição partilhada não duplica |
| C9 | Turma de apoio | mesma contagem, rótulo próprio |
| C10 | `not_held` sem número | nunca entra na sequência nem altera números |
| C11 | Greve (A/B/C) | A conta como ausente; B e C como não realizadas — sem separar nome nem situação no relatório |
| C12 | Interrupção da Fatia 2 | aula retirada pela reconciliação não aparece; `not_held` com causa `school_not_operating`/`weather_closure` num dia que depois vira interrupção não gera «para rever» |

## 2. Privacidade (obrigatório)

| # | Caso | Esperado |
|---|---|---|
| P1 | O relatório de um professor com `not_held` por greve | só «Não realizadas: N» agregado; nunca a palavra «greve» por aula nem a situação |
| P2 | Colega da mesma turma gera o seu relatório | vê só as **suas** ausências; as do colega entram como contagem de turma, sem causa |
| P3 | Observador | não gera relatório de serviço de outro professor |
| P4 | Outra organização | 404 |
| P5 | Exportação (DOCX/PDF/CSV) e pré-visualização | mesmo conteúdo que o ecrã; sem causa/situação/descrição |
| P6 | IA (reescrita de secção) | o prompt não contém causa, situação, descrição nem motivo (`LessonOutcomeLegalTest` estendido) |
| P7 | JSON de props e HTML da primeira visita | sem `outcome_cause*` |

## 3. Integração com o relatório

| # | Caso | Esperado |
|---|---|---|
| R1 | Secção aparece só com o relatório de turma/serviço e o plano certo (Pro) | porta de entitlements |
| R2 | Pré-visualização = documento final (paridade) | mesma `ComposedSection` |
| R3 | Tabela de detalhe cronológico | uma linha por ocorrência: data, contexto, número, resultado; `not_held` sem número |
| R4 | Texto composto | algarismos, sem números por extenso; frase de `Phrase::sentence` |
| R5 | Português europeu e acordos | sem «fatia», sem estrangeirismos |
| R6 | Relatório vazio por intervalo sem aulas | `Absence::noLessons()` |

## 4. Ambientes

SQLite (suite local), MySQL 9.7 (CI) e MySQL 8.0.43 (produção): contagens por `COUNT` com
`NULL` e `NOT IN` têm semânticas diferentes nos três — cada consulta nova prova-se nos
dois motores. Relógio fixo (`travelTo`) em todos os testes: há uma história de testes-bomba.

## 5. Decisões de produto por tomar (não implementar sem resposta)

1. Denominador do «% de aulas dadas»: com ou sem `not_held`?
2. O relatório separa «Não realizadas» por categoria (greve / escola / instalações) ou só total?
   Separar por greve é dado agregado sobre greve: depende da validação jurídica.
3. Quem pode gerar o relatório de serviço de outro professor (responsável da instituição)?
4. Período por omissão: ano letivo, período ou mês.
