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
