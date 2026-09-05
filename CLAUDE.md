# CLAUDE.md — Lapispro

*Mais simples. Mais tempo.*

Modular web app for Portuguese basic/secondary schoolteachers: assessment profiles, classes, instruments, grading grids, deterministic classification proposals, evidence, reports.

> The application handles data about students, most of them minors. Data protection, organization isolation and traceability are structural requirements, not a final polish pass.

## Source of truth

| Document | What it is |
|---|---|
| `G:\...\PROJETOS EM CURSO\LAPIS\Prompt_base_LAPIS_Claude.md` | The 2101-line spec. **Requirements authority.** Section refs below (§n) point here. |
| `docs/domain-model.md` | The assessment data model. Read before any assessment migration. |
| `docs/adr/` | Decisions and their reasoning. Read before contradicting one. |
| `G:\...\LAPIS\Estrutura de menus e submenus da aplicação.docx` | **Canonical navigation.** The `.png` mockups are exploratory and disagree with each other — the doc wins. |

## Stack as actually installed

Laravel 13.20 · PHP 8.4.23 · MySQL 9.7 · Vue 3.5 + TypeScript · Inertia 3 · Vite 8 · Tailwind 4.1 · Fortify (2FA + passkeys) · PHPUnit 12 · Pint · Larastan (level 7)

Scaffolded from `laravel/vue-starter-kit` `dev-main` — see ADR-0003.

## Commands

```powershell
composer setup           # install, key, migrate, npm build
php artisan test         # 68 tests — SQLite in-memory
composer lint            # pint
composer types:check     # larastan (needs --memory-limit=1G, already in the script)
composer ci:check        # everything CI runs
npm run dev              # vite
```

**Local URL:** `http://lapis.test` — `D:\HERD` is a parked Herd path, so **no `herd link` is needed** (it hangs). `herd secure lapis` needs UAC; run it from an elevated terminal if you want trusted TLS.

**Database:** schema `lapis` on the shared DBngin service `base` — **127.0.0.1:3306**, MySQL 8.4.2, root without password. Client:
`C:\Users\Pedro Alves\AppData\Local\com.tinyapp.DBngin\Binaries\mysql\8.4.2\bin\mysql.exe -h 127.0.0.1 -P 3306 -u root`
CI and production run MySQL 9.7 — engine-specific guarantees are what CI covers, not the local server.

## Architecture rules

### Tenancy — read ADR-0002 before touching this

- The tenant lives in the **container** (`App\Support\Tenancy\CurrentOrganization`), **never the session**.
- The global scope **throws** `TenantNotResolvedException` when no tenant is resolved. This is deliberate. Do not make it fall back to "all organizations".
- Jobs, commands, seeders and tests enter a tenant with `CurrentOrganization::runFor($organization, $callback)` — **not** by calling `withoutGlobalScopes()`.
- **Never use a bare `exists:` rule on tenant-owned data.** Laravel's `exists:` runs on the query builder and never sees the scope, so it accepts another organization's id. Use `new BelongsToCurrentOrganization(Model::class)`.
- `withoutGlobalScope('organization')` is for genuine cross-organization reads only (institutional aggregates, support tooling). It should stay rare enough to notice in review.

### Entitlements (§8.2)

- Persisted in tables, not hardcoded: `modules`, `plans`, `module_plan`, `organization_subscriptions`, `organization_module_overrides`.
- Gate routes with `->middleware('module:calendar')`. **Not** a central route→module map — a map drifts the day someone renames a route.
- Checks run on the server. Hiding a menu item is presentation, not access control.
- `EntitlementsSeeder` is reference data and runs in **every** environment. Without it nobody is entitled to anything.

### Code

- English in code/DB, **Portuguese (pt-PT) in the UI**. Locale `pt_PT`, timezone `Europe/Lisbon`, collation `utf8mb4_unicode_ci`.
- ULID in exposed URLs (`getRouteKeyName`), never sequential ids (§11.2).
- `DECIMAL`, never `float`, for anything that becomes a grade (§24.4).
- Form Requests validate · Policies authorize · Actions/Services hold use cases · Enums for stable states.
- Mass assignment is opt-in per model via `#[Fillable]`.
- Descriptive variable names, never abbreviated (`organization` not `org`, `record` not `r`).

### Assessment rules that are not negotiable (§13.3)

- **Empty is not zero.** Never a magic value like `-1` — use the explicit result states (§12.5).
- **"Não aplicável" does not enter the denominator.**
- **Late enrollment** does not turn earlier instruments into zeros (§11.4).
- An **active profile version is frozen**; changes create a new version and history keeps its reference (§10.2).
- **The teacher decides.** The system proposes; it never confirms a classification on its own (§3.3).

## Do not do without asking (§31)

Change the stack · swap the database engine · add Docker locally · build a student/guardian portal · integrate payments · pick an AI provider · **send any identifying student data to an external service** · change the commercial composition of the plans · change assessment formulas · delete historical data · implement SSO · adopt a multi-tenancy package · redesign the root UI structure · deploy to production.

## Definition of Done (§30)

Backend authorization exists · organization isolation considered · server-side validation · error and loading states · tests green · Pint and Larastan clean · docs updated · no real data or secrets · migrations reversible · plan/module impact checked · data-protection impact checked · manual validation steps written · works on Herd/DBngin.

## Orquestração & fluxo de IA

O fluxo assistido por IA é **portável** e vive no repo — não na config global de uma pessoa. Detalhe completo em [docs/workflow.md](docs/workflow.md); o Codex lê [AGENTS.md](AGENTS.md).

- **Equipa** ([.claude/agents/](.claude/agents/)): `explorer` (Haiku, contexto) → `architect` (Opus, **só** decisões estruturais, raro) → `codex-rescue` (implementer por defeito) → `reviewer` (Sonnet, adversarial, depois de cada slice não-trivial). **Sem `fable` neste projeto.**
- **Codex como implementer por defeito**: para tarefas de implementação, delega automaticamente ao `codex-rescue` (via Agent), sem esperar por pedido explícito nem por ficar preso duas vezes. Usa o `implementer` (Sonnet, `.claude/agents/implementer.md`) só como fallback — quando o codex-rescue não estiver disponível, falhar, ou a tarefa for demasiado pequena/trivial (ex.: um edit de uma linha). Continua também a ser usado para segunda implementação em paralelo, diagnóstico independente e revisão cruzada, via `codex` CLI ou subagente `codex:codex-rescue`. Caveat: sandbox Windows instável a escrever → se falhar, lê o diagnóstico e aplica o fix no Claude.
- **Loop por slice** e disciplina por-commit (`composer ci:check` verde · versão em `config/app.php` + `CHANGELOG.md` · memória) — ver [docs/workflow.md](docs/workflow.md). Estado e roadmap em [docs/status.md](docs/status.md).

## Design — [DESIGN.md](DESIGN.md) manda na APP autenticada; [.impeccable.md](.impeccable.md) no site público. Ler antes de tocar em UI

Marca **Sério · Próximo · Claro**; direcção «humano + cor», bento/soft UI, base branca, azul `blue-600` para agir, navy para a faixa forte. Três regras inegociáveis:
- **Nada inventado** — sem estatísticas, logótipos, testemunhos ou funcionalidades que não existem.
- **Sem rostos identificáveis** nas fotos; nunca crianças. Capturas reais do produto antes de mocks.
- **Uma cor forte por página**; WCAG 2.2 AA; páginas públicas só em tema claro.

## Regras de cálculo — decididas, e o que resta

As quatro primeiras questões pedagógicas (bandas de escala, ausências,
arredondamento, «resultado acumulado») **foram decididas** no
[ADR-0004](docs/adr/0004-pedagogical-calculation-rules.md) — lê-o antes de
mexer no cálculo; `docs/domain-model.md` §13 tem o contexto original.

- **Uma só combinação de regras está implementada**: resultado do período por
  média ponderada dos domínios, acumulado sobre todos os elementos válidos do
  ano, arredondamento uma vez na proposta final. As colunas aceitam outros
  valores (o `CHECK` permite-os), mas `ActivateProfileVersion` **recusa ativar**
  uma versão com uma regra que o motor não cumpre, e a explicação do resultado
  nomeia sempre a fase aplicada. Implementar um modo novo é uma decisão
  pedagógica — **não se inventa** (§1).
- **Em aberto:** o formato de importação do Intuitivo (Q5) — falta um ficheiro
  real anonimizado antes de escrever o parser; a especificação diz CSV/XLSX e
  um mockup mostra `.xml`.
