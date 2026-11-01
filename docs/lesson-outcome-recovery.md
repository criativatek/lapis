# Aulas não realizadas — recuperação

`lessons.outcome` ganha o valor `not_held` («Aula não realizada») e três colunas
aditivas: `outcome_cause`, `outcome_teacher_situation` e `outcome_cause_note`
(migração `2026_11_26_000100`). As colunas são inertes para o código anterior;
os **valores** não.

## Antes de voltar o código atrás

```bash
php artisan lapis:lesson-outcome-recovery-check
```

Só leitura: conta as aulas `not_held` e as ausências com motivo `strike`, por
organização, sem ler causas, situações nem descrições.

| Saída | Significado |
|---|---|
| `0` | Nenhum registo novo. Um rollback **só de código** é seguro. |
| `1` | Há registos. O código anterior lança `ValueError` ao ler o enum e a turma inteira deixa de abrir. **Não repor código** abaixo desta versão: corrigir para a frente. |

Voltar o código atrás não desfaz linhas já gravadas. Converter ou apagar essas
linhas é uma operação de dados e precisa de autorização expressa.

## Registo desligado por defeito

`LAPIS_LESSON_NOT_HELD_ENABLED=false` (`config('lapis.lesson_not_held_enabled')`):
o pedido HTTP só aceita `not_held` com a chave ligada. Registar causas — a greve
entre elas — e a situação individual do professor é tratar dados novos. Não
ligar em produção antes de a Política de Privacidade e o Acordo estarem
atualizados e validados juridicamente.

## Backup

Esquema v21 (ver `backup-schema.md`). Um backup v21 é recusado por código
anterior; um v≤20 restaura-se com os campos novos nulos.

## Correções do resultado

O professor que registou um resultado (e só ele) pode corrigi-lo na página da aula
(`PATCH /lessons/{lesson}/outcome`, `RectifyLessonOutcome`), com a mesma chave
`LAPIS_LESSON_NOT_HELD_ENABLED` — desligada, a correção não existe.

- **Detalhes** (causa, especificação da greve, descrição, motivo da ausência): sempre.
- **Resultado**: só entre «Professor ausente» e «Aula não realizada» (ambos sem número,
  sem assiduidade, sem sumário, com o planeamento já descido). Qualquer outra passagem
  recusa-se com a razão; nada se renumera.
- **Histórico**: tabela `lesson_outcome_rectifications` (quem, quando, valores
  anteriores e novos, motivo). Os valores podem trazer dado reservado ao titular: só ele os lê, e a
  auditoria geral (`lesson.outcome_rectified`) leva apenas resultado e causa.
- **Rollback de código**: o código anterior ignora a tabela; o estado atual da aula está em
  `lessons`. O histórico viaja na exportação pessoal (só o das aulas do próprio; ver
  `backup-schema.md`, v21) e restaura-se sem duplicar; a recuperação integral é a cópia diária da base.

### Correção administrativa excecional (titular fora da turma)

`PATCH /lessons/{lesson}/outcome/administrative` (`LessonPolicy::rectifyOutcomeAsAdministrator`).
Reutiliza a competência que o modelo já reconhece à direção da instituição para as retificações
(`SchoolClassPolicy::reviewRectifications`: dono de uma organização **institucional**); não há hierarquia nova.

- Só uma aula `not_held`, só quando o titular do registo (`outcome_recorded_by`) já não pertence à turma, só a **causa** (e a
  descrição de «outra causa»), com motivo obrigatório. Nunca o resultado, nunca um motivo de ausência, nunca uma especificação
  perante a greve em nome de alguém (fica vazia).
- **Corrigir ≠ consultar**: a direção não passa a ler a aula, a especificação, a descrição nem o histórico (a página da aula
  continua 403 para quem não leciona; `LessonOutcomeDetails::mayRead` não mudou). O que o titular reservou fica no histórico
  (`kind = administrative`), que ninguém sem essa leitura vê.
- **O único professor que ficou nunca recebe a permissão por o ser** (testado).
- Limite: **não há ecrã** — a direção não abre a página da aula. Proposta de UI (fora desta versão): uma linha em
  «Retificações» da turma, com a causa e o motivo, sem mostrar nenhum detalhe.
