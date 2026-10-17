# Recuperação da 0.170.0 (registo por sessão das «Outras atividades»)

Este procedimento cobre o caso de a 0.170.0 ter de ser retirada ou contida depois do deploy. Nunca apaga sumários,
sessões nem auditoria, e não usa o restauro integral da base como recuperação normal. O ciclo de vida dos dados está
em `docs/data-lifecycle.md` e o formato do backup em `docs/backup-schema.md`.

**O que a 0.170.0 escreve que a 0.169.1 não sabe ler:**

| Dado novo | Como a 0.169.1 (e anteriores) o trataria |
|---|---|
| Tabela `timetable_activity_sessions` (o sumário, o estado e o número de cada sessão) | Ninguém a lê. Fica na base, **inerte**, com os sumários intactos. |
| `timetable_activities.numbering_enabled` e `numbering_start` | Ninguém as lê. Ficam na base, inertes. |
| `timetable_activities.kind = 'club'` (o tipo «Clube») | **Rebenta.** A 0.169.1 não conhece o valor no enum `TimetableActivityKind` e falha ao ler uma atividade desse tipo (o Horário do Professor deixa de abrir). |
| A CHECK de `kind` alargada a `club` | A 0.169.1 não a repõe nem precisa dela. |
| Backups com `schema_version` 19 | A 0.169.1 recusa-os como mais recentes que o suportado. |

As tabelas e as colunas ficam na base em qualquer cenário: **nunca `migrate:rollback`**, que apagaria a tabela das
sessões com os sumários lá dentro (e falha, de propósito, se já houver atividades «Clube»).

## 1. Que cenário? (com a 0.170.0 ainda instalada)

```bash
cd $APP_DIR            # o diretório da aplicação, ver docs/deployment.md
php artisan lapis:activity-sessions-recovery-check
```

O comando só lê. Diz, por organização e no total, quantas sessões há (e quantas com registos), quantas atividades são
do tipo «Clube» e quantas numeram as sessões — só contagens e ids de organização, nunca títulos nem sumários.

- **sai com 0** → cenário a): não há atividades «Clube»;
- **sai com 1** → cenário b): há atividades «Clube».

## 2. Cenário a) — sem atividades «Clube»

O rollback só de código para a 0.169.1 é uma **recuperação funcional completa**: a tabela e as colunas novas ficam
inertes e as sessões guardadas ficam intactas (voltam a aparecer quando se reinstalar a 0.170.0 ou posterior).

1. Em manutenção (`php artisan down --retry=60`), repor o pacote da 0.169.1 com o bloco de deploy de
   `docs/deployment.md`, **sem** `migrate` e **sem** `migrate:rollback`.
2. `php artisan config:cache && php artisan route:cache && php artisan view:cache`, `php artisan up`, reiniciar o SSR.
3. `php artisan lapis:release-check --expect-version=0.169.1 --expect-commit=<commit dessa versão>`.

O que o professor perde enquanto o código estiver recuado: o registo por sessão deixa de aparecer (os textos
continuam na base) e o histórico. Um backup feito pela 0.170.0 (`schema_version` 19) **não** se restaura no código
anterior — os dumps diários da base continuam válidos.

## 3. Cenário b) — com atividades «Clube»

**Não se repõe código abaixo da 0.170.0.** A 0.169.1 falharia ao ler o tipo. A alternativa segura é avançar:

- **O defeito é do registo por sessão:** corrigir numa 0.170.x a partir de `main`, instalá-la pelo fluxo normal.
- **O defeito é noutra parte da 0.170.0 e o código tem de sair:** construir uma 0.170.x com `git revert` do commit
  culpado, mantendo o enum com `club`. Nunca a 0.169.1.
- **Só se for mesmo indispensável voltar ao código anterior**, e por decisão do responsável: trocar primeiro o tipo
  das atividades «Clube» para «Outra» (uma atualização à mão, de `kind`, que perde a distinção «Clube» mas não toca
  nas sessões) e só depois fazer o cenário a). Nunca apagar as atividades: as sessões ficariam órfãs, identificadas
  pela cópia que levam, mas deixariam de ter um bloco a que voltar.

## 4. O que não se faz

- Repor código abaixo da 0.170.0 havendo atividades «Clube».
- `migrate:rollback` (apaga a tabela das sessões).
- Apagar linhas de `timetable_activity_sessions` ou de `timetable_activities` à mão.
- Restaurar a base inteira a partir de um dump como recuperação normal.

## 5. Verificar no fim

- `php artisan lapis:activity-sessions-recovery-check` — as contagens são iguais às de antes da intervenção.
- `php artisan lapis:release-check` com a versão esperada.

## 6. Ensaio

Coberto por testes automáticos (`tests/Feature/Console/ActivitySessionsRecoveryCheckTest.php`): 0 sem «Clube», 1 com
«Clube», só contagens e nunca títulos nem sumários, e nenhuma escrita.
