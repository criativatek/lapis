# Workflow — desenvolvimento assistido por IA

Como o LÁPIS é construído: um loop por *slice* vertical, uma equipa de agentes,
e o Codex usado ativamente. Portável — vive no repo, não na config de uma pessoa.

## O loop por slice vertical

Cada funcionalidade entra do fundo ao topo, uma fatia de cada vez:

```
migration → model (+ enum) → policy → service → controller → routes →
Vue (Index/Show/Edit) → testes → composer ci:check → verificação no browser →
versão + changelog → memória → commit
```

Exemplo real (Intervenções, §14): `create_interventions_tables` →
`Intervention`/`InterventionReview` + `InterventionStatus`/`InterventionEffectiveness`
→ `InterventionController` (index/show/store/update/addReview/destroy) → rotas
`module:interventions` → `interventions/{Index,Show}.vue` → `InterventionTest`
(6 testes) → `ci:check` → browser (criar → Nova → Em curso) → `0.14.0` + CHANGELOG
→ commit. Uma fatia = um commit coeso, sempre com a suite verde.

Regra de ouro: **um slice fecha só com `composer ci:check` verde** e com a versão
(`config/app.php`) + `CHANGELOG.md` incrementados.

## A equipa (`.claude/agents/`)

| Agente | Modelo | Quando |
|---|---|---|
| `explorer` | Haiku | Levantar contexto antes de escrever — localizar ficheiros, referências, dependências. Read-only. |
| `architect` | Opus | **Antes** de decisões estruturais, migrações importantes, causa de erro não-óbvia. Read-only. É o único Opus — usa com moderação. |
| `codex-rescue` | Codex (plugin) | **Implementer por defeito.** Delegação automática do trabalho de implementação, sem esperar por pedido explícito nem por ficar preso duas vezes. |
| `implementer` | Sonnet | Fallback do `codex-rescue` — só quando este não está disponível, falha, ou a tarefa é demasiado pequena/trivial para justificar a delegação. |
| `reviewer` | Sonnet | **Depois de cada slice não-trivial**, antes de fechar. Revisão adversarial independente. Read-only. |

**Sem Fable neste projeto** — `architect` fica em Opus e `reviewer` em Sonnet,
independentemente de como a config global ou o guia externo posicionem o `fable`.

Regra de delegação: `explorer` para contexto → `architect` **só** se a mudança for
estrutural → `codex-rescue` para o código (fallback: `implementer`) → `reviewer`
antes de fechar. Depois da revisão, corrige autonomamente os problemas relevantes.

Porque o `reviewer` importa: numa sessão real apanhou um **bypass de autorização
cross-turma** (publicar uma turma publicava as classificações confirmadas de outra
turma do mesmo ano letivo), uma **pré-visualização de migração que "mentia"**
(mostrava alterações em notas congeladas que a migração deixa intactas), e
**lacunas de concorrência** (escritas cegas sem `lockForUpdate`). O domínio (dados
de menores, tenancy, rastreabilidade) não perdoa — a revisão é obrigatória.

## Codex — usar e abusar

O Codex **não** é o último recurso "só quando encravado". É o **implementer por
defeito**: o trabalho de implementação delega automaticamente ao `codex-rescue`,
e o `implementer` (Sonnet) fica como fallback. Além disso, continua a ser um par
ativo:

- **Segunda implementação** em paralelo à do Claude, para comparar.
- **Diagnóstico independente** antes de uma decisão difícil.
- **Revisão cruzada** de uma fatia já feita.

Dois caminhos:

```bash
# CLI direto (arranca no repo; lê o AGENTS.md)
codex

# a partir do Claude Code, o subagente:
#   agentType: codex:codex-rescue
```

**Caveat Windows:** o sandbox do Codex é instável a **escrever** — se o Codex não
conseguir aplicar uma alteração, lê o diagnóstico dele e aplica o fix no Claude.
Isto é sobre fiabilidade de escrita, não motivo para reservar o Codex para
emergências.

## Verificação no browser (após mudança de UI)

Padrão usado em todas as fatias:

1. Chrome com debugging: `chrome --remote-debugging-port=9222 --user-data-dir=<tmp>`.
2. MCP `chrome-devtools`: `navigate_page` → login demo (`ana.martins@lapis.test` / `password`).
3. Navegar para a página; `take_snapshot` para os uids; `fill`/`click` nos inputs Vue.
4. `take_screenshot` para confirmar; `list_console_messages` (erros/warns) deve vir limpo.

## Testes e armadilhas de ambiente

**Local corre SQLite in-memory; a CI corre MySQL 9.7.** SQLite **não** valida CHECK
constraints, colunas geradas nem comprimentos de VARCHAR — a CI é a rede de
segurança. Armadilhas já vividas (todas reais):

- `addCheck()` nas migrations só corre em MySQL/MariaDB (guardado por `getDriverName()`).
- Palavra reservada MySQL (ex.: `trigger`) numa coluna precisa de backticks no CHECK.
- **VARCHAR curto demais para o próprio valor** — `filled_by VARCHAR(16)` rejeitava
  `'teacher_interview'` (17 chars) em MySQL; SQLite deixava passar. Confere os
  comprimentos contra os valores dos enums/CHECK.
- `whereKey($ulid)` coage a string para `int` numa coluna id — resolve ULID sempre
  por `where('ulid', $x)`.
- Relação em cache após update de FK: `$class->update(['..._id' => $v])` não recarrega
  `$class->profileVersion`; usa `setRelation()` antes de reusar.
- `pluck('valor', 'coluna_enum')` rebenta se a coluna-chave tem cast enum — usa
  `mapWithKeys(fn ($row) => [$row->status->value => ...])`.
- `npm run build` corre `php artisan wayfinder:generate`: um **parse-error PHP em
  qualquer ficheiro** faz o build falhar com um `RolldownError` críptico — diagnostica
  com `php artisan wayfinder:generate --with-form`.
- Páginas Vue **novas** precisam de `npm run build` antes de os testes de Inertia as
  resolverem (senão "Not a valid Inertia response").

Antes de fechar algo com CHECK/colunas geradas: confia na CI (MySQL) ou corre um
`migrate:fresh` real contra a BD MySQL local (:3308).

## Versão, changelog, memória

- Incrementa `config/app.php` `version` (semver pré-1.0) **e** `CHANGELOG.md` a cada commit.
- Guarda decisões técnicas, armadilhas e feedback do utilizador em memória (não código efémero).
